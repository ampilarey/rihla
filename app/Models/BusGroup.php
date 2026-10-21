<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One coach on one departure, with whoever leads it.
 *
 * Members are booking travellers — the person *on this booking*, not the
 * traveller in the abstract, because somebody who travels twice sits on a
 * different bus each time.
 *
 * `leader_name` is free text. A bus is led by whoever the office says is
 * standing at its door, which is sometimes a tour leader on the system and
 * often somebody's brother-in-law; a foreign key would make the common
 * case impossible to record. With nothing named, the departure's tour
 * leader is taken to lead the coach — the right answer for a one-coach
 * departure, which is most of them.
 *
 * @property int $departure_id
 * @property string $name
 * @property int|null $capacity
 * @property string|null $leader_name
 * @property string|null $leader_phone
 * @property int $sort_order
 * @property string|null $notes
 */
class BusGroup extends Model
{
    protected $fillable = ['departure_id', 'name', 'capacity', 'leader_name', 'leader_phone', 'sort_order', 'notes'];

    protected $casts = ['capacity' => 'integer', 'sort_order' => 'integer'];

    protected static function booted(): void
    {
        // No foreign key on `booking_travellers.bus_group_id` (see the
        // migration), so the referential work is done here: a deleted bus
        // leaves its people on no bus, not on a bus that is gone.
        static::deleting(function (BusGroup $bus): void {
            $bus->members()->update(['bus_group_id' => null, 'checked_in_at' => null]);
        });
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return HasMany<BookingTraveller, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(BookingTraveller::class);
    }

    public function leaderLabel(): ?string
    {
        return $this->leader_name ?: $this->departure->tourLeader?->name;
    }

    public function seatsFree(): ?int
    {
        return $this->capacity === null ? null : $this->capacity - $this->members()->count();
    }

    public function isOverfull(): bool
    {
        return $this->capacity !== null && $this->members()->count() > $this->capacity;
    }

    public function checkedInCount(): int
    {
        return $this->members()->whereNotNull('checked_in_at')->count();
    }
}
