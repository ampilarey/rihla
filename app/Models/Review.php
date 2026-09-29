<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's review of a stay — §16.11.
 *
 * Written once, by the guest, after the stay is `completed`. Published by
 * itself after a short delay unless a person at Rihla hides it; hidden
 * reviews are kept and count for nothing. The host may reply once.
 */
class Review extends Model
{
    use HasFactory;

    /** Sub-ratings a guest may give besides the overall one. All optional. */
    public const ASPECTS = [
        'cleanliness' => 'Cleanliness',
        'accuracy' => 'As described',
        'communication' => 'Communication',
        'value' => 'Value',
    ];

    /** How long a host may still correct a reply once written. */
    public const REPLY_EDITABLE_HOURS = 24;

    // Nothing is fillable from a form: the service writes every column,
    // and the stay, host and customer come from the stay, never the request.
    protected $fillable = [];

    protected $casts = [
        'rating' => 'integer',
        'cleanliness' => 'integer',
        'accuracy' => 'integer',
        'communication' => 'integer',
        'value' => 'integer',
        'submitted_at' => 'datetime',
        'published_at' => 'datetime',
        'hidden_at' => 'datetime',
        'host_replied_at' => 'datetime',
    ];

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * What the public sees and what the averages count: published by now,
     * and not hidden.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereNull('hidden_at');
    }

    /**
     * The same rule for a query typed only as a builder of models — a
     * relation's constraint closure, a table filter — where the scope is
     * invisible to static analysis (AGENTS.md). Keep the two in step.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function onlyVisible(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereNull('hidden_at');
    }

    public function isVisible(): bool
    {
        return $this->published_at !== null && $this->published_at->lte(now()) && $this->hidden_at === null;
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /** The guest's first name only, on a public page. */
    public function authorName(): string
    {
        $name = trim((string) $this->customer?->name);

        return $name === '' ? 'A guest' : (string) strtok($name, ' ');
    }

    public function replyIsEditable(): bool
    {
        return $this->host_replied_at === null
            || $this->host_replied_at->gt(now()->subHours(self::REPLY_EDITABLE_HOURS));
    }
}
