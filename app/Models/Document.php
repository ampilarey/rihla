<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One document about one traveller — a passport, a photograph, a bank slip.
 *
 * The file is never here. Each upload is a {@see DocumentVersion}; this row
 * is the thing itself and its status, and it outlives every version of it.
 */
class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'traveller_id', 'booking_id', 'category', 'type', 'expires_at', 'notes',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'verified_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::PENDING];

    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::VERIFIED, self::REJECTED];

    public const TRAVEL = 'travel';

    public const IDENTITY = 'identity';

    public const FINANCIAL = 'financial';

    public const MEDICAL = 'medical';

    /** @var list<string> */
    public const CATEGORIES = [self::TRAVEL, self::IDENTITY, self::FINANCIAL, self::MEDICAL];

    public const PASSPORT = 'passport';

    /** @return BelongsTo<Traveller, $this> */
    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Every version, newest first. Nothing is ever removed from this list —
     * that is the whole point of [R-8].
     *
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): ?DocumentVersion
    {
        return $this->versions->firstWhere('superseded_at', null)
            ?? $this->versions->first();
    }

    /**
     * How close the document is to being a problem.
     *
     * Computed, never stored: a stored "expiring soon" flag is wrong from
     * the moment the clock passes it, and the window is configuration
     * because Saudi requirements change and the plan (§5.4b) is explicit
     * that they must not be constants in code.
     */
    public function expiresWithinWindow(?\DateTimeInterface $travelDate = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        $months = (int) config('documents.passport_validity_months', 6);

        // Carbon::parse rather than modify(): DateTimeInterface promises
        // neither modify() nor immutability, so cloning and modifying it is
        // only safe for the subset of implementations that happen to be
        // DateTime. Callers pass a departure date, which is a Carbon here
        // and could be anything later.
        $reference = Carbon::parse($travelDate ?? now());

        return $this->expires_at->lt($reference->addMonths($months));
    }

    /** @param  Builder<$this>  $query */
    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    /** @param  Builder<$this>  $query */
    public function scopeExpiringBefore($query, \DateTimeInterface $date)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', $date);
    }
}
