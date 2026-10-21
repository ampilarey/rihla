<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a reader proposed for one upload, and what staff then accepted —
 * batch B from Hijaz. Append-only: a second reading is a second row, and
 * what was actually saved is written here, which is how a later
 * cross-check knows what each document said.
 *
 * @property int $document_version_id
 * @property string $reader
 * @property string|null $model
 * @property array<string, mixed>|null $fields
 * @property array<string, float>|null $confidence
 * @property string|null $raw_text
 * @property bool|null $mrz_valid
 * @property string $status
 * @property array<string, mixed>|null $accepted_fields
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class DocumentExtraction extends Model
{
    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'fields' => 'array',
        'confidence' => 'array',
        'accepted_fields' => 'array',
        'mrz_valid' => 'boolean',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<DocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isProposed(): bool
    {
        return $this->status === self::PROPOSED;
    }

    /** Something to accept: proposed, and with fields to show. */
    public function hasProposal(): bool
    {
        return $this->isProposed() && ($this->fields ?? []) !== [];
    }

    /**
     * Fields the reader was unsure about, worth a second look first.
     *
     * @return list<string>
     */
    public function lowConfidenceFields(float $below = 0.7): array
    {
        return array_keys(array_filter($this->confidence ?? [], fn (float $c): bool => $c < $below));
    }
}
