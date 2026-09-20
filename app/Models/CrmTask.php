<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Something somebody has to do, by a date — §8.1's follow-up tasks.
 *
 * ## One next action was never enough
 *
 * `enquiries.next_action_at` holds a single date. Real follow-up is "ring
 * them Tuesday, and chase the deposit on the 14th", and one column forces
 * the second to be forgotten or to overwrite the first.
 *
 * ## It hangs off whatever it is about
 *
 * An enquiry, a customer or a booking. "Ring them about their passport" is
 * not a lead, and pretending it is means inventing a fake enquiry to hold a
 * reminder — which then shows up in the pipeline as a deal nobody is
 * working.
 *
 * ## Done is a time and a person, not a flag
 *
 * "Who said this was handled, and when" is the question anybody asks about
 * a task that turns out not to have been.
 */
/**
 * A task can be unowned, unfinished and about nothing — `owner_id`,
 * `done_by`, `about_id` and `due_on` are all nullable columns. Larastan
 * reads a `BelongsTo<User, $this>` as returning `User`, so without these
 * it calls every `?->` here unnecessary and would have them removed.
 *
 * @property-read ?User $owner
 * @property-read ?User $completer
 * @property-read ?Carbon $due_on
 * @property-read ?Carbon $done_at
 */
class CrmTask extends Model
{
    use HasFactory;

    protected $table = 'crm_tasks';

    protected $fillable = ['about_type', 'about_id', 'subject', 'detail', 'due_on', 'owner_id'];

    protected $casts = [
        'due_on' => 'date',
        'done_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $task): void {
            $task->created_by ??= Auth::id();
            // Unowned work is nobody's work. Falling back to whoever wrote
            // it is better than a null that quietly means "anyone".
            $task->owner_id ??= Auth::id();
        });
    }

    /** @return MorphTo<Model, $this> */
    public function about(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function complete(?User $actor = null): void
    {
        if ($this->done_at !== null) {
            return;
        }

        $this->forceFill([
            'done_at' => now(),
            'done_by' => ($actor ?? Auth::user())?->getKey(),
        ])->save();
    }

    /** Somebody marked it done by mistake, or it turned out not to be. */
    public function reopen(): void
    {
        $this->forceFill(['done_at' => null, 'done_by' => null])->save();
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_on?->endOfDay()->isPast() === true;
    }

    public function isDueToday(): bool
    {
        return ! $this->isDone() && $this->due_on?->isToday() === true;
    }

    /** What the row says at a glance, in words rather than a date to subtract. */
    public function whenLabel(): string
    {
        return match (true) {
            $this->isDone() => 'Done',
            $this->due_on === null => 'No date',
            $this->isOverdue() => 'Overdue — '.$this->due_on->translatedFormat('j M'),
            $this->isDueToday() => 'Today',
            default => $this->due_on->translatedFormat('j M'),
        };
    }

    /**
     * What this is about, in one line a reader recognises.
     *
     * A morph is a class name; nobody in the office knows what
     * `App\Models\Enquiry` is.
     */
    public function aboutLabel(): string
    {
        $about = $this->about;

        return match (true) {
            $about instanceof Enquiry => 'Enquiry from '.$about->name,
            $about instanceof Customer => $about->name,
            $about instanceof Booking => 'Booking '.$about->reference,
            default => 'Not linked to anything',
        };
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @return Builder<CrmTask>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @return Builder<CrmTask>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('done_at')->whereDate('due_on', '<', now()->toDateString());
    }

    /**
     * @param  Builder<CrmTask>  $query
     * @return Builder<CrmTask>
     */
    public function scopeDueBy(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->whereNull('done_at')->whereDate('due_on', '<=', $date);
    }
}
