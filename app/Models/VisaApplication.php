<?php

namespace App\Models;

use App\Exceptions\IllegalVisaTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * One visa application, for one traveller, on one booking, on one attempt.
 *
 * Deliberately knows nothing about Nusuk permits [R-4]: they are a different
 * authorisation from a different system, and a traveller can hold this and
 * still be turned away at the Rawdah without one.
 *
 * Status moves only through {@see transitionTo()}, which refuses an illegal
 * move and records who, why and what they saw.
 */
class VisaApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'traveller_id', 'attempt', 'visa_type', 'reference',
        'assigned_to', 'expires_at', 'notes',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'submitted_at' => 'datetime',
        'issued_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expires_at' => 'date',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempt' => 1,
        'status' => self::NOT_STARTED,
    ];

    /** Nothing has happened yet. */
    public const NOT_STARTED = 'not_started';

    /** Gathering what the submission needs. */
    public const PREPARING = 'preparing';

    /** With the issuing system, waiting. */
    public const SUBMITTED = 'submitted';

    public const ISSUED = 'issued';

    /** Refused. Final — trying again is a new attempt, not a reopening. */
    public const REJECTED = 'rejected';

    /** The booking went away, or the traveller did. */
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::NOT_STARTED, self::PREPARING, self::SUBMITTED,
        self::ISSUED, self::REJECTED, self::CANCELLED,
    ];

    /**
     * Where each status may go next.
     *
     * `rejected` is final on purpose, and that is §5.4a's "re-application
     * after rejection is a first-class path" read literally: a refusal is a
     * fact about a particular submission — its date, its reference, its
     * stated reason — and reopening the row to try again destroys the only
     * evidence of what was actually sent. Attempt 2 is a new row.
     *
     * `submitted` may go back to `preparing`: an application returned for
     * more information has not been refused, and treating that as a
     * rejection would burn an attempt that nobody refused.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::NOT_STARTED => [self::PREPARING, self::CANCELLED],
        self::PREPARING => [self::SUBMITTED, self::CANCELLED],
        self::SUBMITTED => [self::ISSUED, self::REJECTED, self::PREPARING, self::CANCELLED],
        self::ISSUED => [self::CANCELLED],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Traveller, $this> */
    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    /** @return BelongsTo<User, $this> */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<VisaApplicationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(VisaApplicationEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Move to a new status, recording who, why, and what they saw.
     *
     * The evidence document is the §5.4a requirement that every stage
     * carries one: the submission receipt, the visa scan, the refusal
     * letter. A stage with a date and no evidence is somebody's memory.
     */
    public function transitionTo(
        string $status,
        ?string $reason = null,
        ?User $actor = null,
        ?Document $evidence = null,
    ): void {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw IllegalVisaTransition::from($this, $status);
        }

        $from = $this->status;

        $this->forceFill(array_filter([
            'status' => $status,
            'submitted_at' => $status === self::SUBMITTED ? now() : null,
            'issued_at' => $status === self::ISSUED ? now() : null,
            'rejected_at' => $status === self::REJECTED ? now() : null,
            'rejection_reason' => $status === self::REJECTED ? $reason : null,
        ], fn ($value): bool => $value !== null))->save();

        $this->events()->create([
            'from_status' => $from,
            'to_status' => $status,
            'user_id' => ($actor ?? Auth::user())?->getKey(),
            'document_id' => $evidence?->getKey(),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** Settled one way or the other; nothing is waiting on anybody. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::ISSUED, self::REJECTED, self::CANCELLED], true);
    }

    /**
     * Has this stage been sitting too long?
     *
     * Computed from config/visa.php, never stored. A stored "overdue" flag
     * is wrong the moment the clock passes it, and right again only if
     * something remembers to clear it.
     */
    public function isStalled(): bool
    {
        $days = config('visa.sla_days.'.$this->status);

        if (! is_int($days) || $days < 1) {
            return false;
        }

        $since = $this->status === self::SUBMITTED
            ? $this->submitted_at
            : $this->updated_at;

        return $since !== null && $since->addDays($days)->isPast();
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::ISSUED, self::REJECTED, self::CANCELLED]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeForBooking($query, Booking|int $booking)
    {
        return $query->where('booking_id', $booking instanceof Booking ? $booking->getKey() : $booking);
    }
}
