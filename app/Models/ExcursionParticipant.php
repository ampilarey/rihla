<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's place on an additional trip, at the price agreed when they
 * were signed up (companies plan, Phase 3e). The price is kept on the row
 * for the reason a booking traveller keeps its own: the trip may be
 * repriced afterwards, and this is what was agreed.
 *
 * @property int $excursion_booking_id
 * @property int $booking_traveller_id
 * @property int $amount_minor
 */
class ExcursionParticipant extends Model
{
    use BelongsToCompany;

    protected $fillable = ['excursion_booking_id', 'booking_traveller_id', 'amount_minor'];

    protected $casts = ['amount_minor' => 'integer'];

    /** @return BelongsTo<ExcursionBooking, $this> */
    public function excursionBooking(): BelongsTo
    {
        return $this->belongsTo(ExcursionBooking::class);
    }

    /** @return BelongsTo<BookingTraveller, $this> */
    public function bookingTraveller(): BelongsTo
    {
        return $this->belongsTo(BookingTraveller::class);
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['excursionBooking'];
    }
}
