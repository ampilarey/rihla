<?php

namespace Modules\Travel\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Notice;
use App\Models\User;
use App\Support\EncryptedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Telegram\Services\Notifier;
use Modules\Travel\Models\TravelDocument;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\TravelRequestEvent;
use Modules\Travel\Models\TravelVisaApplication;
use Modules\Travel\Models\VisaProduct;

/**
 * The visa desk for other countries — docs/TRAVEL_AGENCY_PLAN.md (A3).
 *
 * An application is opened from a trip request with the visa list's
 * checklist copied onto it. The customer sends each document from their
 * trip page (or staff tick one handed in on paper); once everything is in,
 * staff submit it to the embassy and record the decision.
 *
 * Collected files go into the trip's own encrypted store — the same
 * `EncryptedFile` and documents disk as the Umrah wallet. The Umrah wallet
 * itself is tied to Umrah travellers and companies, which a trip abroad
 * has neither of.
 */
final class VisaDesk
{
    public function __construct(private readonly TravelDocuments $documents) {}

    public function open(TravelRequest $request, VisaProduct $product, string $applicant, ?User $by): TravelVisaApplication
    {
        if ($request->status === TravelRequest::CANCELLED) {
            throw new DeskRefusal('That request is cancelled.');
        }

        if (! $product->is_active) {
            throw new DeskRefusal('That visa is not on the list any more.');
        }

        if (trim($applicant) === '') {
            throw new DeskRefusal('Name the applicant, as on their passport.');
        }

        $application = new TravelVisaApplication;
        $application->forceFill([
            'travel_request_id' => $request->getKey(),
            'visa_product_id' => $product->getKey(),
            'applicant_name' => trim($applicant),
            'status' => TravelVisaApplication::COLLECTING,
            'checklist' => array_map(fn (string $item): array => ['item' => $item, 'received_at' => null], $product->documents),
            'opened_by' => $by?->getKey(),
        ])->save();

        TravelRequestEvent::record($request, 'visa', $by, $product->label().' for '.$application->applicant_name);

        return $application;
    }

    /** The customer sends one document on the checklist from their trip page. */
    public function receive(TravelVisaApplication $application, int $index, UploadedFile $file): TravelDocument
    {
        $line = $this->openLine($application, $index);
        /** @var TravelRequest $request */
        $request = $application->request()->firstOrFail();

        $path = 'travel/'.$request->getKey().'/visa-'.$application->getKey().'/'.Str::random(40).'.'.($file->extension() ?: 'bin');
        EncryptedFile::put($this->documents->disk(), $path, (string) file_get_contents($file->getRealPath()));

        $document = new TravelDocument;
        $document->forceFill([
            'travel_request_id' => $request->getKey(),
            'visa_application_id' => $application->getKey(),
            'from_customer' => true,
            'kind' => 'other',
            'label' => $line['item'].' — '.$application->applicant_name,
            'disk' => $this->documents->disk(),
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
        ])->save();

        $this->markReceived($application, $index);

        if ($application->fresh()?->isComplete() === true) {
            app(Notifier::class)->tell('travel.quote', '🛂 '.$request->reference.': every document for '.$application->applicant_name.'\'s visa is in. It can be submitted.');
        }

        return $document;
    }

    /** Staff tick a document handed in on paper. */
    public function tick(TravelVisaApplication $application, int $index, ?User $by): void
    {
        $this->openLine($application, $index);
        $this->markReceived($application, $index);
    }

    public function submit(TravelVisaApplication $application, ?User $by): void
    {
        if ($application->status !== TravelVisaApplication::COLLECTING) {
            throw new DeskRefusal('It has already been submitted.');
        }

        if (! $application->isComplete()) {
            throw new DeskRefusal(($application->received()).' of '.count($application->checklist).' documents are in. Collect the rest first.');
        }

        $application->forceFill(['status' => TravelVisaApplication::SUBMITTED, 'submitted_at' => now()])->save();
        TravelRequestEvent::record($application->request()->firstOrFail(), 'visa', $by, 'Submitted for '.$application->applicant_name);
    }

    public function decide(TravelVisaApplication $application, bool $approved, ?string $note, ?User $by): void
    {
        if ($application->status !== TravelVisaApplication::SUBMITTED) {
            throw new DeskRefusal('Only a submitted application has a decision.');
        }

        if (! $approved && blank($note)) {
            throw new DeskRefusal('Say why it was refused: the customer will ask.');
        }

        $application->forceFill([
            'status' => $approved ? TravelVisaApplication::APPROVED : TravelVisaApplication::REFUSED,
            'decided_at' => now(),
            'decision_note' => filled($note) ? trim((string) $note) : null,
        ])->save();

        /** @var TravelRequest $request */
        $request = $application->request()->firstOrFail();
        TravelRequestEvent::record($request, 'visa', $by, ($approved ? 'Approved' : 'Refused').' for '.$application->applicant_name);

        Notice::raise(
            $request,
            Notice::TRAVEL_VISA_DECIDED,
            $application->applicant_name.'\'s visa is '.($approved ? 'approved' : 'refused'),
            (filled($note) ? trim((string) $note)."\n\n" : '').'Your trip page: '.route('travel.requests.show', ['locale' => 'en', 'key' => $request->status_key]),
        );
    }

    /** @return array{item: string, received_at: string|null} */
    private function openLine(TravelVisaApplication $application, int $index): array
    {
        if ($application->status !== TravelVisaApplication::COLLECTING) {
            throw new DeskRefusal('This application has been submitted; nothing more is collected for it.');
        }

        $line = $application->checklist[$index] ?? null;

        if ($line === null) {
            throw new DeskRefusal('That is not on the checklist.');
        }

        return $line;
    }

    private function markReceived(TravelVisaApplication $application, int $index): void
    {
        $checklist = $application->checklist;
        $checklist[$index]['received_at'] = now()->toIso8601String();
        $application->forceFill(['checklist' => $checklist])->save();
    }
}
