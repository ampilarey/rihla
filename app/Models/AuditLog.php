<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
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
     * Who did it, falling back to the snapshot when the account is gone and
     * finally to a plain statement that it was not a person.
     */
    public function getActorAttribute(): string
    {
        return $this->user?->name
            ?? $this->user_name
            ?? __('System');
    }
}
