<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Payments\TakesPayments;
use App\Support\CompanyDetails;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * One family's places on an additional trip (companies plan, Phase 3e).
 *
 * Its own payable: priced in the trip's currency (riyal, usually), with its
 * own reference, payments and invoice, so money paid in Saudi Arabia never
 * has to be squeezed into a booking kept in rufiyaa. The Ledger keeps
 * `paid_minor`, as it does a booking's; nothing else writes it.
 *
 * @property int $id
 * @property string|null $reference
 * @property int $departure_excursion_id
 * @property int $booking_id
 * @property int $customer_id
 * @property string $currency
 * @property int $total_minor
 * @property int $paid_minor
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 */
class ExcursionBooking extends Model implements TakesPayments
{
    use BelongsToCompany;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['departure_excursion_id', 'booking_id', 'customer_id', 'currency', 'notes'];

    protected $casts = ['total_minor' => 'integer', 'paid_minor' => 'integer'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::OPEN, 'currency' => 'SAR', 'total_minor' => 0, 'paid_minor' => 0];

    protected static function booted(): void
    {
        static::creating(function (ExcursionBooking $booking): void {
            $booking->created_by ??= Auth::id();
        });

        // From the primary key after the insert, as a booking's is: a count
        // races, and two people signing families up in the same second
        // would mint the same number.
        static::created(function (ExcursionBooking $booking): void {
            if ($booking->reference === null) {
                $booking->forceFill(['reference' => sprintf(
                    '%s-%s-%s',
                    CompanyDetails::referencePrefix($booking->company_id, 'X', 'RIH-X'),
                    now()->format('Y'),
                    str_pad((string) $booking->getKey(), 4, '0', STR_PAD_LEFT),
                )])->saveQuietly();
            }
        });
    }

    /** @return BelongsTo<DepartureExcursion, $this> */
    public function excursion(): BelongsTo
    {
        return $this->belongsTo(DepartureExcursion::class, 'departure_excursion_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<ExcursionParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ExcursionParticipant::class);
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable')->orderByDesc('id');
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    public function paid(): Money
    {
        return Money::ofMinor($this->paid_minor, $this->currency);
    }

    public function balance(): Money
    {
        return Money::ofMinor($this->total_minor - $this->paid_minor, $this->currency);
    }

    /** The participants are the truth; the total is their cached sum. */
    public function recalculateTotal(): void
    {
        $this->forceFill(['total_minor' => (int) $this->participants()->sum('amount_minor')]);
        $this->status = $this->settledStatus();
        $this->save();
    }

    public function paymentCurrency(): string
    {
        return $this->currency;
    }

    public function storePaidTotal(int $minor): void
    {
        $this->forceFill(['paid_minor' => $minor]);
        $this->status = $this->settledStatus();
        $this->save();
    }

    private function settledStatus(): string
    {
        if ($this->status === self::CANCELLED) {
            return self::CANCELLED;
        }

        return $this->total_minor > 0 && $this->paid_minor >= $this->total_minor ? self::PAID : self::OPEN;
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['booking', 'excursion'];
    }
}
