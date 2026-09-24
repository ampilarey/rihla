<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing to do before a departure leaves — §8.3.
 *
 * `is_blocking` is the office's call, not a category: "group visa
 * submitted" stops a departure if it is not done, "welcome pack printed"
 * does not. The departure board raises an overdue item as blocking only
 * when somebody has said so.
 */
class DepartureChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'departure_id', 'title', 'due_on', 'is_blocking', 'notes', 'sort_order',
    ];

    protected $casts = [
        'due_on' => 'date',
        'is_blocking' => 'boolean',
        'done_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** Past its date and not done. An item with no date is never overdue. */
    public function isOverdue(): bool
    {
        return ! $this->isDone()
            && $this->due_on !== null
            && $this->due_on->lt(today());
    }

    /**
     * Ticked by a person, recorded as that person.
     *
     * Written with forceFill because `done_at` and `done_by` are not
     * fillable: a form that could set them could say somebody else did it.
     */
    public function markDone(User $by): void
    {
        $this->forceFill(['done_at' => now(), 'done_by' => $by->getKey()])->save();
    }

    public function markNotDone(): void
    {
        $this->forceFill(['done_at' => null, 'done_by' => null])->save();
    }

    /**
     * @param  Builder<DepartureChecklistItem>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('done_at');
    }
}
