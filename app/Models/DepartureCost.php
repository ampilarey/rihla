<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One line of what a departure costs to run — §8.4.
 *
 * @property-read ?User $author
 */
class DepartureCost extends Model
{
    use HasFactory;

    protected $fillable = [
        'departure_id', 'category', 'supplier', 'description',
        'currency', 'amount_minor', 'is_per_person', 'status', 'incurred_on',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'is_per_person' => 'boolean',
        'incurred_on' => 'date',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::ESTIMATED,
        'currency' => 'MVR',
    ];

    public const HOTEL = 'hotel';

    public const FLIGHT = 'flight';

    public const TRANSPORT = 'transport';

    public const VISA = 'visa';

    public const PERMIT = 'permit';

    public const STAFF = 'staff';

    public const FOOD = 'food';

    public const OTHER = 'other';

    /** @var list<string> */
    public const CATEGORIES = [
        self::HOTEL, self::FLIGHT, self::TRANSPORT, self::VISA,
        self::PERMIT, self::STAFF, self::FOOD, self::OTHER,
    ];

    /** A plan. Somebody's best guess, and it will move. */
    public const ESTIMATED = 'estimated';

    /** A contract somebody signed. It will not move, and it is not paid. */
    public const COMMITTED = 'committed';

    /** Money that has left the account. */
    public const PAID = 'paid';

    /** @var list<string> */
    public const STATUSES = [self::ESTIMATED, self::COMMITTED, self::PAID];

    protected static function booted(): void
    {
        static::creating(function (self $cost): void {
            $cost->created_by ??= Auth::id();
        });
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What is on the row: the unit price, not the total. */
    public function unit(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * What this line actually costs for a given number of travellers.
     *
     * The distinction the whole table exists for: a coach costs the same
     * for eighteen people as for thirty, and a hotel bed does not.
     */
    public function totalFor(int $travellers): Money
    {
        return Money::ofMinor(
            $this->is_per_person ? $this->amount_minor * max(0, $travellers) : $this->amount_minor,
            $this->currency,
        );
    }

    /** Whole words, never a key built by concatenation. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ESTIMATED => 'Estimate',
            self::COMMITTED => 'Agreed, not paid',
            self::PAID => 'Paid',
            default => 'Unknown',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::HOTEL => 'Hotels',
            self::FLIGHT => 'Flights',
            self::TRANSPORT => 'Transport',
            self::VISA => 'Visas',
            self::PERMIT => 'Permits',
            self::STAFF => 'Leader and scholar',
            self::FOOD => 'Food',
            self::OTHER => 'Everything else',
            default => 'Unknown',
        };
    }

    public function basisLabel(): string
    {
        return $this->is_per_person ? 'Per person' : 'Whole departure';
    }

    /**
     * @param  Builder<DepartureCost>  $query
     * @return Builder<DepartureCost>
     */
    public function scopeCounting(Builder $query, string $upTo): Builder
    {
        // "Up to paid" means paid only; "up to estimated" means everything,
        // because an estimate is the loosest thing that counts.
        return $query->whereIn('status', match ($upTo) {
            self::PAID => [self::PAID],
            self::COMMITTED => [self::COMMITTED, self::PAID],
            default => self::STATUSES,
        });
    }
}
