<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An instalment: this much by this date.
 *
 * Information for the customer and a nudge for the office, not a rule the
 * ledger enforces. A booking's money is still recorded and checked exactly
 * as it always was; this only says when the parts were expected, so the
 * invoice can print the dates and Today can say which have fallen behind.
 *
 * In the booking's currency: a booking has one, and a second copy here
 * would be a second thing to keep in step.
 *
 * @property int $booking_id
 * @property string $label
 * @property int $amount_minor
 * @property Carbon $due_on
 */
class PaymentSchedule extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'label', 'amount_minor', 'due_on', 'sort_order'];

    protected $casts = [
        'amount_minor' => 'integer',
        'due_on' => 'date',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->booking->currency);
    }

    /**
     * Due, and not covered by what has been checked in so far.
     *
     * Earlier instalments are counted first: a customer who paid the
     * deposit late and the second part on time is behind on nothing, and
     * one who paid half of each is behind on the first. Only checked money
     * counts, because a claim nobody has looked at is not a payment.
     */
    public function isOverdue(): bool
    {
        if (! $this->due_on->isPast() || $this->due_on->isToday()) {
            return false;
        }

        $booking = $this->booking;

        if (! $booking->isLive()) {
            return false;
        }

        // whereDate, not where: the column holds midnight as well as the
        // day, and "2027-01-15 00:00:00" is not <= "2027-01-15" as text.
        $dueSoFar = (int) $booking->schedule()
            ->whereDate('due_on', '<=', $this->due_on->toDateString())
            ->sum('amount_minor');

        return $booking->paid_minor < $dueSoFar;
    }

    /** What is still owed on this instalment, after the earlier ones have been covered. */
    public function outstanding(): Money
    {
        $booking = $this->booking;

        $dueBefore = (int) $booking->schedule()
            ->whereDate('due_on', '<', $this->due_on->toDateString())
            ->sum('amount_minor');

        $left = $booking->paid_minor - $dueBefore;

        return Money::ofMinor(max(0, $this->amount_minor - max(0, $left)), $booking->currency);
    }
}
