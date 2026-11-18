<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The people of one departure who fly out on one flight and home on
 * another, with the buses that carry them on the ground (companies plan,
 * Phase 3c, from Hijaz's trip groups).
 *
 * Each traveller is in one group at most; a bus belongs to one group at
 * most. A departure that flies on one aircraft needs no group at all, and
 * nothing requires one.
 *
 * @property int $departure_id
 * @property string $name
 * @property int|null $outbound_flight_id
 * @property int|null $return_flight_id
 * @property string|null $notes
 * @property int $sort_order
 * @property int|null $members_count
 */
class FlightGroup extends Model
{
    use BelongsToCompany;

    protected $fillable = ['departure_id', 'name', 'outbound_flight_id', 'return_flight_id', 'notes', 'sort_order'];

    protected $casts = [
        'outbound_flight_id' => 'integer',
        'return_flight_id' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        // No foreign key on either `flight_group_id` (see the migration):
        // a deleted group leaves its people and buses on the departure, in
        // no group, rather than in a group that is gone.
        static::deleting(function (FlightGroup $group): void {
            BookingTraveller::query()->where('flight_group_id', $group->getKey())->update(['flight_group_id' => null]);
            BusGroup::query()->where('flight_group_id', $group->getKey())->update(['flight_group_id' => null]);
        });
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<DepartureFlight, $this> */
    public function outboundFlight(): BelongsTo
    {
        return $this->belongsTo(DepartureFlight::class, 'outbound_flight_id');
    }

    /** @return BelongsTo<DepartureFlight, $this> */
    public function returnFlight(): BelongsTo
    {
        return $this->belongsTo(DepartureFlight::class, 'return_flight_id');
    }

    /** @return HasMany<BookingTraveller, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(BookingTraveller::class);
    }

    /** @return HasMany<BusGroup, $this> */
    public function buses(): HasMany
    {
        return $this->hasMany(BusGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    /** "SV 3301 30 Nov / SV 3302 14 Dec", or what is known. */
    public function flightsLabel(): string
    {
        return self::flightPart($this->outboundFlight).' / '.self::flightPart($this->returnFlight);
    }

    public static function flightPart(?DepartureFlight $flight): string
    {
        if ($flight === null) {
            return 'no flight yet';
        }

        return trim(trim((string) $flight->flight_number).' '.$flight->departs_at->format('j M'));
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['departure'];
    }
}
