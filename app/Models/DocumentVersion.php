<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded file, kept for ever.
 *
 * A replaced passport creates version 2 and marks version 1 superseded; the
 * row and the file both stay. A visa was applied for against a particular
 * passport, and when the traveller renews mid-process the only way to answer
 * "which document did we send them?" is to still have it.
 */
class DocumentVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'size_bytes' => 'integer',
        'superseded_at' => 'datetime',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /** "2.4 MB" — for a list, where the byte count means nothing to anybody. */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $this->size_bytes.' B';
    }
}
