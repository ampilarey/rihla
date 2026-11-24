<?php

namespace Modules\Transport\Models;

use App\Exceptions\DeskRefusal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One departure of a route, on one boat, at one time —
 * docs/TRANSPORT_PLAN.md. `seats_taken` is held plus confirmed, written only
 * by TransportDesk under this row's lock.
 *
 * @property Carbon $departs_at
 */
class Sailing extends Model
{
    public const SCHEDULED = 'scheduled';

    public const BOARDING = 'boarding';

    public const DEPARTED = 'departed';

    public const CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const STATUSES = [
        self::SCHEDULED => 'Scheduled',
        self::BOARDING => 'Boarding',
        self::DEPARTED => 'Departed',
        self::CANCELLED => 'Cancelled',
    ];

    // `seats_taken`, `status` and the reason are the desk's to write.
    protected $fillable = ['boat_route_id', 'vessel_id', 'departs_at', 'seats_total'];

    protected $casts = [
        'departs_at' => 'datetime',
        'seats_total' => 'integer',
        'seats_taken' => 'integer',
    ];

    protected static function booted(): void
    {
        // However a sailing is made — the timetable, the form, a seeder — it
        // never sells more seats than its boat has, and never runs a route on
        // another operator's boat.
        static::saving(function (self $sailing): void {
            $vessel = Vessel::query()->find($sailing->vessel_id);
            $route = BoatRoute::query()->find($sailing->boat_route_id);

            if ($vessel === null || $route === null) {
                throw new DeskRefusal('Choose a route and a boat.');
            }

            if ($vessel->partner_id !== $route->partner_id) {
                throw new DeskRefusal('That boat belongs to another operator.');
            }

            if ($sailing->seats_total > $vessel->seats) {
                throw new DeskRefusal('That boat has only '.$vessel->seats.' seats.');
            }
        });
    }

    /** @return BelongsTo<BoatRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(BoatRoute::class, 'boat_route_id');
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return HasMany<BoatBooking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(BoatBooking::class);
    }

    /**
     * Sailings a seat can still be sold on.
     *
     * @param  Builder<Sailing>  $query
     */
    public function scopeOnSale(Builder $query): void
    {
        $query->where('status', self::SCHEDULED)->where('departs_at', '>', now());
    }

    public function seatsLeft(): int
    {
        return max(0, $this->seats_total - $this->seats_taken);
    }

    public function isOnSale(): bool
    {
        return $this->status === self::SCHEDULED && $this->departs_at->isFuture();
    }
}
