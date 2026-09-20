<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Traveller;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Everything that writes to the document wallet.
 *
 * ## Replacing never overwrites ([R-8])
 *
 * A new passport creates version 2 and marks version 1 superseded. Both rows
 * and both files stay. A visa was applied for against a particular passport,
 * and when the traveller renews mid-process the only way to answer "which
 * document did we send them?" is to still have it.
 *
 * ## Checksums earn their place twice
 *
 * The SHA-256 proves a file has not changed under us, and it catches the
 * common case where somebody re-uploads the *same* file — a customer
 * resending on WhatsApp, a staff member unsure whether the first attempt
 * worked. That produces no new version, because nothing changed, and a
 * wallet full of identical "versions" makes the real history unreadable.
 *
 * ## Paths are not guessable
 *
 * A file's name on disk is a random string, not the traveller's name and not
 * the original filename. The disk is private and not servable
 * (config/filesystems.php), so the path alone reaches nothing — but a
 * predictable layout is one misconfiguration away from being an index.
 */
final class DocumentWallet
{
    /**
     * Put a file in the wallet, as a new version if it differs from the last.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function store(
        Traveller $traveller,
        UploadedFile $file,
        string $category,
        string $type,
        array $attributes = [],
    ): DocumentVersion {
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        return DB::transaction(function () use ($traveller, $file, $category, $type, $attributes, $checksum): DocumentVersion {
            $document = Document::firstOrCreate(
                ['traveller_id' => $traveller->getKey(), 'type' => $type],
                ['category' => $category] + $attributes,
            );

            if ($attributes !== []) {
                $document->fill($attributes)->save();
            }

            $latest = $document->versions()->orderByDesc('version')->first();

            // Byte-for-byte the same file. Nothing has changed, so nothing
            // is versioned; the caller gets the version that already holds
            // it.
            if ($latest instanceof DocumentVersion && $checksum !== null && $latest->checksum === $checksum) {
                return $latest;
            }

            $path = $file->storeAs(
                $this->directoryFor($document),
                Str::random(40).'.'.($file->getClientOriginalExtension() ?: 'bin'),
                ['disk' => $this->disk()],
            );

            $version = $document->versions()->create([
                'version' => ($latest?->version ?? 0) + 1,
                'disk' => $this->disk(),
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize() ?: 0,
                'checksum' => $checksum,
                'uploaded_by' => Auth::id(),
            ]);

            // Superseded, not deleted. The previous file stays exactly where
            // it is.
            $latest?->forceFill(['superseded_at' => now()])->save();

            // A replaced document is unverified again: somebody has to look
            // at the new one.
            $document->forceFill([
                'status' => Document::PENDING,
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ])->save();

            return $version;
        });
    }

    public function verify(Document $document, ?int $userId = null): void
    {
        $document->forceFill([
            'status' => Document::VERIFIED,
            'verified_by' => $userId ?? Auth::id(),
            'verified_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    public function reject(Document $document, string $reason, ?int $userId = null): void
    {
        $document->forceFill([
            'status' => Document::REJECTED,
            'verified_by' => $userId ?? Auth::id(),
            'verified_at' => now(),
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * A short-lived signed URL, which is the only route to a file.
     *
     * Short because the link is the only thing between a passport scan and
     * whoever ends up holding the URL — a chat history, a proxy log, a
     * screenshot over somebody's shoulder.
     */
    public function downloadUrl(DocumentVersion $version): string
    {
        return URL::temporarySignedRoute(
            'documents.download',
            now()->addMinutes((int) config('documents.download_link_minutes', 5)),
            ['version' => $version->getKey()],
        );
    }

    public function exists(DocumentVersion $version): bool
    {
        return Storage::disk($version->disk)->exists($version->path);
    }

    /**
     * Where a document's files live.
     *
     * Grouped by document id rather than by traveller name: a directory
     * listing should say nothing about whose documents these are.
     */
    private function directoryFor(Document $document): string
    {
        return 'documents/'.$document->getKey();
    }

    private function disk(): string
    {
        return (string) config('documents.disk', 'documents');
    }
}
