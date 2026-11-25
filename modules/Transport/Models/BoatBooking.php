<?php

namespace Modules\Transport\Models;

use App\Models\Concerns\HasNotices;
use App\Models\Customer;
use App\Services\Payments\TakesPayments;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One booker's seats on one sailing — docs/TRANSPORT_PLAN.md. Held while
 * the names go in, confirmed by staff or by being paid in full, and
 * cancelled or lapsed otherwise; every change goes through TransportDesk,
 * which keeps the sailing's seat counter.
 *
 * @property Carbon|null $held_until
 * @property int|null $commission_pct
 * @property int $paid_minor
 * @property int $total_minor
 * @property string $currency
 */
class BoatBooking extends Model implements TakesPayments
{
    use HasNotices;

    public const HELD = 'held';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const LAPSED = 'lapsed';

    /** @var array<string, string> */
    public const STATUSES = [
        self::HELD => 'Held',
        self::CONFIRMED => 'Confirmed',
        self::CANCELLED => 'Cancelled',
        self::LAPSED => 'Lapsed',
    ];

    /** Held and confirmed bookings hold seats; the others gave theirs back. */
    public const HOLDS_SEATS = [self::HELD, self::CONFIRMED];

    protected $fillable = [];

    protected $casts = [
        'seats' => 'integer',
        'total_minor' => 'integer',
        'paid_minor' => 'integer',
        'held_until' => 'datetime',
        'commission_pct' => 'integer',
    ];

    /** @return BelongsTo<Sailing, $this> */
    public function sailing(): BelongsTo
    {
        return $this->belongsTo(Sailing::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<BoatTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(BoatTicket::class);
    }

    public function paymentCurrency(): string
    {
        return $this->currency;
    }

    /**
     * The ledger's re-derived total. Paid in full confirms a held booking:
     * its seats are already counted, so this changes only the status.
     */
    public function storePaidTotal(int $minor): void
    {
        $this->forceFill(['paid_minor' => $minor]);

        if ($this->status === self::HELD && $minor >= $this->total_minor) {
            $this->forceFill(['status' => self::CONFIRMED, 'held_until' => null]);
        }

        $this->save();
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function owed(): Money
    {
        return Money::ofMinor(max(0, $this->total_minor - $this->paid_minor), $this->currency);
    }

    public function holdsSeats(): bool
    {
        return in_array($this->status, self::HOLDS_SEATS, true);
    }
}
