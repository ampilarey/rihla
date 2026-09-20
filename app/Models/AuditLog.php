<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property-read User|null $user The account that made the change, or null
 *     once it is deleted: the foreign key is nulled rather than cascaded so
 *     the record outlives the account.
 *
 * An immutable record of one change.
 *
 * Deliberately has no fillable list and no update path: entries are written
 * by the observer and never edited. An audit trail that can be rewritten
 * proves nothing.
 */
class AuditLog extends Model
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * Somebody pulled a file out of the document wallet.
     *
     * For most records the interesting event is a change; for a passport
     * scan it is a read. "Who has had a copy of this?" is the question that
     * gets asked after something goes wrong, and a trail recording only
     * uploads cannot answer it.
     */
    public const DOWNLOADED = 'downloaded';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** The class basename, for display: "Trip" rather than "App\Models\Trip". */
    public function getSubjectAttribute(): string
    {
        return class_basename($this->auditable_type);
    }

    /**
     * Who did it, at the time they did it.
     *
     * Reads the snapshot rather than the live relation deliberately. The
     * observer writes user_name on every row, so it is always there; and if
     * someone later changes their name, an audit trail that showed the new one
     * against an old action would be rewriting history. Null means the change
     * did not come from a signed-in person.
     */
    public function getActorAttribute(): string
    {
        return $this->user_name ?? __('System');
    }
}
