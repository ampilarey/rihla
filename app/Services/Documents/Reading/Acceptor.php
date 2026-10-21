<?php

namespace App\Services\Documents\Reading;

use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Traveller;
use App\Models\VisaApplication;
use App\Services\Documents\DocumentWallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Staff looked at the scan beside the proposed fields and said yes. Only
 * now do the fields reach the traveller's record; what was actually saved
 * is written on the reading, which is how a later cross-check knows what
 * each document said.
 */
class Acceptor
{
    /**
     * Traveller columns each document type may fill. Nothing else is
     * written from a reading.
     *
     * @var array<string, list<string>>
     */
    public const TRAVELLER_FIELDS = [
        Document::NATIONAL_ID => ['full_name', 'national_id', 'gender', 'date_of_birth'],
        Document::PASSPORT => ['full_name', 'passport_number', 'gender', 'date_of_birth', 'passport_expiry', 'passport_issuing_country', 'nationality'],
        Document::VISA => [],
    ];

    /**
     * Which proposed field is the document's own expiry, per type.
     *
     * @var array<string, string>
     */
    public const EXPIRY_FIELD = [
        Document::NATIONAL_ID => 'id_expiry',
        Document::PASSPORT => 'passport_expiry',
        Document::VISA => 'expires_on',
    ];

    public function __construct(
        private readonly DocumentWallet $wallet,
        private readonly IdentityCheck $identity,
    ) {}

    /**
     * @param  array<string, mixed>  $fields  the values as corrected on screen; blank means "do not write"
     * @param  bool  $verify  also mark the document verified
     * @param  bool  $issueVisa  for a visa copy: mark the open visa application issued, with this document as its evidence
     */
    public function accept(DocumentExtraction $extraction, array $fields, bool $verify = false, bool $issueVisa = false): Traveller
    {
        $document = $extraction->version->document;
        $type = (string) $document->type;
        $fields = array_filter($fields, fn ($value): bool => $value !== null && $value !== '');

        return DB::transaction(function () use ($extraction, $document, $type, $fields, $verify, $issueVisa): Traveller {
            $traveller = $document->traveller;
            $allowed = self::TRAVELLER_FIELDS[$type] ?? [];
            $writes = [];

            foreach (array_intersect_key($fields, array_flip($allowed)) as $field => $value) {
                $writes[$field] = self::normalise($field, (string) $value);
            }

            // A code the table does not know is left alone, not guessed.
            $writes = array_filter($writes, fn ($value): bool => $value !== null);

            if ($writes !== []) {
                $traveller->fill($writes)->save();
            }

            $expiryField = self::EXPIRY_FIELD[$type] ?? null;

            if ($expiryField !== null && isset($fields[$expiryField])) {
                $document->expires_at = (string) $fields[$expiryField];
            }

            $document->save();

            $extraction->forceFill([
                'status' => DocumentExtraction::ACCEPTED,
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'accepted_fields' => $fields,
            ])->save();

            if ($verify) {
                $this->wallet->verify($document);
            }

            if ($issueVisa && $type === Document::VISA) {
                $this->issueVisa($traveller, $document, $fields);
            }

            $this->identity->run($traveller->fresh());

            return $traveller->fresh();
        });
    }

    public function reject(DocumentExtraction $extraction): void
    {
        $extraction->forceFill([
            'status' => DocumentExtraction::REJECTED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * The visa application a visa copy settles: the newest one still open
     * on a booking that has not travelled yet.
     */
    public static function openVisaApplication(Traveller $traveller): ?VisaApplication
    {
        return VisaApplication::query()
            ->where('traveller_id', $traveller->getKey())
            ->whereIn('status', [VisaApplication::NOT_STARTED, VisaApplication::PREPARING, VisaApplication::SUBMITTED])
            ->whereHas('booking', fn ($booking) => $booking
                ->whereIn('status', [Booking::HELD, Booking::CONFIRMED])
                ->whereHas('departure', fn ($departure) => $departure->where('date_end', '>=', today())))
            ->orderByDesc('id')
            ->first();
    }

    /** @param  array<string, mixed>  $fields */
    private function issueVisa(Traveller $traveller, Document $document, array $fields): void
    {
        $application = self::openVisaApplication($traveller);

        if ($application === null) {
            return;
        }

        $application->forceFill(array_filter([
            'reference' => isset($fields['number']) ? mb_substr((string) $fields['number'], 0, 60) : null,
            'expires_at' => $fields['expires_on'] ?? null,
        ], fn ($value): bool => $value !== null))->save();

        // The copy is the evidence that it was submitted and issued; a
        // visa in hand was applied for, whatever the office recorded.
        foreach ([VisaApplication::PREPARING, VisaApplication::SUBMITTED, VisaApplication::ISSUED] as $status) {
            if (in_array($status, VisaApplication::TRANSITIONS[$application->status] ?? [], true)) {
                $application->transitionTo($status, 'Visa copy accepted.', null, $status === VisaApplication::ISSUED ? $document : null);
            }
        }
    }

    private static function normalise(string $field, string $value): ?string
    {
        return match ($field) {
            'nationality', 'passport_issuing_country' => Countries::iso2($value),
            'gender' => in_array(strtolower($value), Traveller::GENDERS, true) ? strtolower($value) : null,
            'national_id', 'passport_number' => strtoupper(trim($value)),
            default => trim($value),
        };
    }
}
