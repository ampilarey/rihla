<?php

namespace App\Models;

use App\Exceptions\IllegalPermitTransition;
use App\Exceptions\PrerequisitesNotMet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * One Nusuk permit — an Umrah permit, or a Rawdah slot.
 *
 * Separate records rather than one with two dates, because they are granted
 * separately, refused separately, and fail differently: a missing Rawdah
 * slot is a disappointment, a missing Umrah permit is a wasted journey.
 *
 * Knows nothing about visas [R-4]. A traveller holding this may still lack a
 * visa, and a traveller holding a visa may still lack this — which is the
 * whole reason the two workflows are apart.
 */
class NusukPermit extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id', 'traveller_id', 'kind', 'attempt', 'reference',
        'slot_at', 'assigned_to', 'notes',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'slot_at' => 'datetime',
        'requested_at' => 'datetime',
        'issued_at' => 'datetime',
        'refused_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'attempt' => 1,
        'status' => self::NOT_STARTED,
        'kind' => self::UMRAH,
    ];

    /** Without this, the Mataf is closed to them. */
    public const UMRAH = 'umrah';

    /** A visit to the Rawdah. Desirable, not a blocker. */
    public const RAWDAH = 'rawdah';

    /** @var list<string> */
    public const KINDS = [self::UMRAH, self::RAWDAH];

    public const NOT_STARTED = 'not_started';

    /** With Nusuk, waiting. */
    public const REQUESTED = 'requested';

    public const ISSUED = 'issued';

    public const REFUSED = 'refused';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::NOT_STARTED, self::REQUESTED, self::ISSUED, self::REFUSED, self::CANCELLED,
    ];

    /**
     * `refused` is final, as a visa refusal is: re-requesting is a new
     * attempt, so the refusal keeps its date and its stated reason.
     *
     * An issued permit may be cancelled — Saudi systems do withdraw them,
     * and a record that cannot express that would be wrong at the worst
     * possible moment.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::NOT_STARTED => [self::REQUESTED, self::CANCELLED],
        self::REQUESTED => [self::ISSUED, self::REFUSED, self::CANCELLED],
        self::ISSUED => [self::CANCELLED],
        self::REFUSED => [],
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

    /** @return HasMany<NusukPermitEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(NusukPermitEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Move a stage, recording who, why and what they saw.
     *
     * Requesting checks the prerequisite gate first: §5.4b requires
     * Nusuk-compliant accommodation and transport to be recorded before a
     * request can be made, and a request that will be rejected for a reason
     * we could have seen is a wasted round trip and a confusing status.
     *
     * @throws IllegalPermitTransition
     * @throws PrerequisitesNotMet
     */
    public function transitionTo(
        string $status,
        ?string $reason = null,
        ?User $actor = null,
        ?Document $evidence = null,
    ): void {
        if (! in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw IllegalPermitTransition::from($this, $status);
        }

        if ($status === self::REQUESTED) {
            $missing = self::missingPrerequisites($this->booking->departure);

            if ($missing !== []) {
                throw PrerequisitesNotMet::on($this->booking->departure, $missing);
            }
        }

        $from = $this->status;

        $this->forceFill(array_filter([
            'status' => $status,
            'requested_at' => $status === self::REQUESTED ? now() : null,
            'issued_at' => $status === self::ISSUED ? now() : null,
            'refused_at' => $status === self::REFUSED ? now() : null,
            'refusal_reason' => $status === self::REFUSED ? $reason : null,
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

    /**
     * What Nusuk still wants recorded on this departure.
     *
     * Read from configuration, because §5.4b says every Saudi requirement
     * must be — turning one off is then a deliberate act with a name against
     * it rather than a code change nobody notices.
     *
     * @return list<string>
     */
    public static function missingPrerequisites(Departure $departure): array
    {
        $missing = [];

        if (config('nusuk.prerequisites.accommodation') && $departure->nusuk_accommodation_recorded_at === null) {
            $missing[] = 'accommodation';
        }

        if (config('nusuk.prerequisites.transport') && $departure->nusuk_transport_recorded_at === null) {
            $missing[] = 'transport';
        }

        return $missing;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::ISSUED, self::REFUSED, self::CANCELLED], true);
    }

    /** Computed from config, never stored. */
    public function isStalled(): bool
    {
        $days = config('nusuk.sla_days.'.$this->status);

        return is_int($days)
            && $days > 0
            && $this->requested_at !== null
            && $this->requested_at->addDays($days)->isPast();
    }

    /**
     * A Rawdah slot booked further ahead than Nusuk is thought to allow.
     *
     * A warning and never a refusal: Nusuk decides what it will accept, and
     * a client-side rule that quietly blocks a valid request is worse than
     * no rule at all.
     */
    public function slotLooksOutOfRange(): bool
    {
        if ($this->kind !== self::RAWDAH || $this->slot_at === null) {
            return false;
        }

        $days = (int) config('nusuk.rawdah_slot_lead_days', 30);

        return $days > 0 && $this->slot_at->isAfter(now()->addDays($days));
    }

    /** @param  Builder<$this>  $query */
    public function scopeOpen($query)
    {
        return $query->whereNotIn('status', [self::ISSUED, self::REFUSED, self::CANCELLED]);
    }

    /** @param  Builder<$this>  $query */
    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }
}
