<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DocumentVersion;
use App\Services\Documents\DocumentWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to a document file.
 *
 * The disk is private and `serve => false`, so nothing else can reach one.
 * Three things happen before a byte is sent: the signature on the URL is
 * checked by middleware, the policy is asked, and the download is written to
 * the audit trail.
 *
 * **Every download is audited, not just every change.** For most records the
 * interesting event is an edit; for a passport scan it is a read. "Who has
 * had a copy of this?" is the question that will be asked after something
 * goes wrong, and a trail that only records uploads cannot answer it.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentWallet $wallet) {}

    public function download(DocumentVersion $version, Request $request): StreamedResponse
    {
        $this->authorize('download', $version->document);

        abort_unless($this->wallet->exists($version), 404);

        $this->recordDownload($version, $request);

        return Storage::disk($version->disk)->download(
            $version->path,
            $version->original_filename,
        );
    }

    /**
     * Straight into `audit_logs` rather than a second trail of its own.
     *
     * The filename and the checksum are recorded; the file is not, and
     * neither is anything that would let somebody reconstruct it. The point
     * is who looked and when.
     */
    private function recordDownload(DocumentVersion $version, Request $request): void
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => AuditLog::DOWNLOADED,
            'auditable_type' => $version->getMorphClass(),
            'auditable_id' => $version->getKey(),
            'old_values' => null,
            'new_values' => [
                'document_id' => $version->document_id,
                'version' => $version->version,
                'original_filename' => $version->original_filename,
                'checksum' => $version->checksum,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'url' => $request->fullUrl(),
        ]);
    }
}
