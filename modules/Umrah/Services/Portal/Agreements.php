<?php

namespace Modules\Umrah\Services\Portal;

use App\Exceptions\DeskRefusal;
use App\Models\User;
use App\Support\CompanyDetails;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Umrah\Models\Agreement;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Document;
use Modules\Umrah\Services\Documents\DocumentWallet;

/**
 * Signs a booking's agreement (companies plan, Phase 3h, part 3, from
 * Hijaz): on screen by the customer, or as a scan of a signed paper copy
 * from either side. Refused when the company has written no terms, and
 * never signed twice.
 */
final class Agreements
{
    /** The largest drawn signature accepted, as a data URI. */
    private const MAX_SIGNATURE_BYTES = 300_000;

    public function __construct(private readonly DocumentWallet $wallet) {}

    /** Whether there is anything to agree to: the company's own terms. */
    public static function offered(): bool
    {
        return filled(CompanyDetails::terms());
    }

    public function for(Booking $booking): Agreement
    {
        return Agreement::query()->firstOrCreate(['booking_id' => $booking->getKey()]);
    }

    public function signOnScreen(Booking $booking, string $name, string $signature, ?string $ip, ?string $userAgent): Agreement
    {
        $name = trim($name);

        if (mb_strlen($name) < 3) {
            throw new DeskRefusal(__('messages.Type your full name as you sign.'));
        }

        // A PNG the browser drew, and nothing else: not a link, not a script.
        if (! str_starts_with($signature, 'data:image/png;base64,') || strlen($signature) > self::MAX_SIGNATURE_BYTES
            || ($png = base64_decode(substr($signature, 22), true)) === false || ! str_starts_with($png, "\x89PNG")) {
            throw new DeskRefusal(__('messages.Draw your signature in the box, then sign.'));
        }

        return $this->sign($booking, [
            'method' => Agreement::ON_SCREEN,
            'signed_name' => mb_substr($name, 0, 160),
            'signature' => $signature,
            'signed_ip' => $ip,
            'signed_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
    }

    /**
     * A signed paper copy. The scan is kept with the lead traveller's
     * documents, so it has the protections a passport scan has: staff
     * download it under `document.download`, and data:forget reaches it.
     */
    public function recordPaper(Booking $booking, UploadedFile $scan, ?User $by = null): Agreement
    {
        $lead = $booking->leadTraveller()?->traveller;

        if ($lead === null) {
            throw new DeskRefusal('This booking has nobody on it to keep the scan with.');
        }

        return DB::transaction(function () use ($booking, $scan, $by, $lead): Agreement {
            $this->wallet->store($lead, $scan, Document::FINANCIAL, Document::AGREEMENT, ['booking_id' => $booking->getKey()], read: false);

            return $this->sign($booking, ['method' => Agreement::PAPER, 'recorded_by' => $by?->getKey()]);
        });
    }

    /** @param  array<string, mixed>  $how */
    private function sign(Booking $booking, array $how): Agreement
    {
        if (! self::offered()) {
            throw new DeskRefusal('There are no terms to agree to yet. They are set in the company\'s settings.');
        }

        return DB::transaction(function () use ($booking, $how): Agreement {
            $agreement = Agreement::query()->lockForUpdate()->firstOrCreate(['booking_id' => $booking->getKey()]);

            if ($agreement->isSigned()) {
                throw new DeskRefusal(__('messages.This agreement is already signed.'));
            }

            $agreement->forceFill($how + ['wording' => CompanyDetails::terms(), 'signed_at' => now()])->save();

            return $agreement;
        });
    }
}
