<?php

namespace Modules\Transport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One passenger on a booking — docs/TRANSPORT_PLAN.md. The token is what
 * the QR code carries (T2) and the crew scans at boarding (T3); it opens
 * nothing by itself.
 *
 * @property Carbon|null $boarded_at
 * @property int|null $boarded_by
 * @property string $full_name
 * @property string $token
 */
class BoatTicket extends Model
{
    protected $fillable = [];

    protected $casts = [
        'is_child' => 'boolean',
        'boarded_at' => 'datetime',
    ];

    /** @return BelongsTo<BoatBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(BoatBooking::class, 'boat_booking_id');
    }
}
