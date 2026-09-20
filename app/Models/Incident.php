<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Something that went wrong on a trip — §8.3, and the minimum §6.5 asks for.
 *
 * ## Three severities, and each one means something
 *
 * Not a five-point scale. An undefined scale gets used as a mood ring:
 * everything is a 3 until something goes badly wrong and then everything is
 * a 5. These three can be told apart by the person typing.
 *
 * ## The narrative is append-only
 *
 * Notes are added, never edited. An incident report that can be quietly
 * rewritten after the fact is not evidence, and this is the record that
 * gets read if anything ever reaches a lawyer or a regulator — the same
 * reasoning as [R-8]'s supersede-never-overwrite on documents. `resolution`
 * is the one field written at the end, and the note trail says what led
 * there.
 */
class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_uuid',
        'departure_id', 'traveller_id',
        'severity', 'category', 'summary', 'detail',
        'happened_at', 'location',
        'assigned_to',
    ];

    protected $casts = [
        'happened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN];

    /** Handled on the spot, recorded so it is not lost. */
    public const MINOR = 'minor';

    /** The office needs to know today. */
    public const SERIOUS = 'serious';

    /** Somebody needs to act now. */
    public const EMERGENCY = 'emergency';

    /** @var list<string> Most serious first: this order is the screen's order. */
    public const SEVERITIES = [self::EMERGENCY, self::SERIOUS, self::MINOR];

    public const MEDICAL = 'medical';

    public const LOST_DOCUMENT = 'lost_document';

    public const MISSING_PERSON = 'missing_person';

    public const TRANSPORT = 'transport';

    public const ACCOMMODATION = 'accommodation';

    public const CONDUCT = 'conduct';

    public const OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::MEDICAL, self::LOST_DOCUMENT, self::MISSING_PERSON,
        self::TRANSPORT, self::ACCOMMODATION, self::CONDUCT, self::OTHER,
    ];

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    /** @var list<string> */
    public const STATUSES = [self::OPEN, self::RESOLVED];

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            $incident->recorded_by ??= Auth::id();
            $incident->happened_at ??= now();
        });

        static::created(function (self $incident): void {
            if ($incident->reference === null) {
                $incident->forceFill(['reference' => $incident->makeReference()])->save();
            }
        });
    }

    /** "INC-2026-0007". Human-readable, because it is read out over a phone. */
    public function makeReference(): string
    {
        return sprintf('INC-%s-%04d', $this->created_at->format('Y'), $this->getKey());
    }

    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(IncidentNote::class)->oldest();
    }

    /**
     * Close it, saying how.
     *
     * A resolution is required. "Resolved" with no sentence is the state
     * this exists to prevent: six months later nobody can say what was
     * done, and the record is worse than useless because it looks complete.
     */
    public function resolve(string $resolution, ?User $actor = null): void
    {
        $this->forceFill([
            'status' => self::RESOLVED,
            'resolution' => $resolution,
            'resolved_at' => now(),
        ])->save();

        $this->notes()->create([
            'author_id' => $actor?->getKey() ?? Auth::id(),
            'body' => 'Resolved: '.$resolution,
        ]);
    }

    /** Reopened because it was not actually over. The trail keeps both. */
    public function reopen(string $why, ?User $actor = null): void
    {
        $this->forceFill([
            'status' => self::OPEN,
            'resolved_at' => null,
        ])->save();

        $this->notes()->create([
            'author_id' => $actor?->getKey() ?? Auth::id(),
            'body' => 'Reopened: '.$why,
        ]);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * An open emergency with nobody on it.
     *
     * The one query this table exists to answer. A screen that cannot find
     * these is a diary, not an operations tool.
     */
    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeUnattended(Builder $query): Builder
    {
        return $query->where('status', self::OPEN)
            ->where('severity', self::EMERGENCY)
            ->whereNull('assigned_to');
    }

    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    /**
     * Whole words, never a key built by concatenation. A concatenated key
     * cannot be checked by anything, and would put a raw string on the
     * screen the day a severity is added — which TranslationTest exists to
     * stop.
     */
    public function severityLabel(): string
    {
        return match ($this->severity) {
            self::EMERGENCY => 'Emergency',
            self::SERIOUS => 'Serious',
            self::MINOR => 'Minor',
            default => 'Unknown',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::MEDICAL => 'Medical',
            self::LOST_DOCUMENT => 'Lost document',
            self::MISSING_PERSON => 'Missing person',
            self::TRANSPORT => 'Transport',
            self::ACCOMMODATION => 'Accommodation',
            self::CONDUCT => 'Conduct',
            self::OTHER => 'Other',
            default => 'Unknown',
        };
    }
}
