<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Traveller;

/** One person in one room. */
class RoomAssignment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['room_id', 'traveller_id', 'booking_id', 'assigned_by'];

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

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
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['room', 'traveller', 'booking'];
    }
}
