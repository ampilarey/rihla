<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move of a departure along its lifecycle: from what, to what, by
 * whom and why. Append-only, like the visa and permit events: the board
 * reads the latest to say how long a departure has sat where it is, and
 * the office reads the list to see who closed bookings and the reason.
 *
 * @property int $departure_id
 * @property string|null $from_status
 * @property string $to_status
 * @property int|null $user_id
 * @property string|null $reason
 * @property Carbon|null $created_at
 */
class DepartureEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
