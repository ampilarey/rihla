<?php

namespace Modules\Travel\Services;

use App\Exceptions\DeskRefusal;
use App\Models\AuditLog;
use App\Models\Notice;
use App\Models\User;
use App\Support\AuditContext;
use App\Support\EncryptedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Travel\Models\TravelDocument;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\TravelRequestEvent;

/**
 * Fulfilment — docs/TRAVEL_AGENCY_PLAN.md (A2). Staff upload what they
 * issued once the trip is paid; the customer downloads it from their trip
 * page; every download is in the audit trail, because a ticket carries a
 * passport number.
 */
final class TravelDocuments
{
    public function disk(): string
    {
        return (string) config('documents.disk', 'documents');
    }

    public function attach(TravelRequest $request, UploadedFile $file, string $kind, string $label, ?User $by): TravelDocument
    {
        if (! in_array($request->status, [TravelRequest::PAID, TravelRequest::ISSUED], true)) {
            throw new DeskRefusal('Documents are issued once the trip is paid.');
        }

        if (! array_key_exists($kind, TravelDocument::KINDS)) {
            throw new DeskRefusal('Choose what it is: an e-ticket, a hotel voucher, a visa or other.');
        }

        $path = 'travel/'.$request->getKey().'/'.Str::random(40).'.'.($file->extension() ?: 'bin');
        EncryptedFile::put($this->disk(), $path, (string) file_get_contents($file->getRealPath()));

        $document = new TravelDocument;
        $document->forceFill([
            'travel_request_id' => $request->getKey(),
            'kind' => $kind,
            'label' => trim($label) !== '' ? trim($label) : TravelDocument::KINDS[$kind],
            'disk' => $this->disk(),
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by' => $by?->getKey(),
        ])->save();

        return $document;
    }

    /** The file and its record go together. */
    public function remove(TravelDocument $document): void
    {
        Storage::disk($document->disk)->delete($document->path);
        $document->delete();
    }

    /** Everything is issued: the customer is told their documents are ready. */
    public function markIssued(TravelRequest $request, ?User $by): void
    {
        if ($request->status !== TravelRequest::PAID) {
            throw new DeskRefusal($request->status === TravelRequest::ISSUED ? 'It is already issued.' : 'Only a paid trip is issued.');
        }

        if (! $request->documents()->exists()) {
            throw new DeskRefusal('Upload the tickets, vouchers or visas first.');
        }

        $request->forceFill(['status' => TravelRequest::ISSUED])->save();
        TravelRequestEvent::record($request, TravelRequest::ISSUED, $by, $request->documents()->count().' document(s)');

        Notice::raise(
            $request,
            Notice::TRAVEL_ISSUED,
            'Your '.$request->wants().' to '.$request->destination.' is booked: your documents are ready',
            'Download them from your trip page: '.route('travel.requests.show', ['locale' => 'en', 'key' => $request->status_key]),
        );
    }

    /**
     * The decrypted file, with the download recorded.
     *
     * @return array{contents: string, name: string, mime: string}
     */
    public function download(TravelDocument $document, ?User $user, string $by): array
    {
        $contents = EncryptedFile::contents($document->disk, $document->path);

        if ($contents === null) {
            throw new DeskRefusal('That file is missing. Ask us to upload it again.');
        }

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => AuditLog::DOWNLOADED,
            'channel' => AuditContext::channel(),
            'auditable_type' => $document->getMorphClass(),
            'auditable_id' => $document->getKey(),
            'old_values' => null,
            'new_values' => ['document' => $document->kind, 'by' => $by],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            'url' => request()->fullUrl(),
        ]);

        return ['contents' => $contents, 'name' => $document->original_name, 'mime' => $document->mime ?: 'application/octet-stream'];
    }
}
