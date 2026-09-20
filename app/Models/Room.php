<?php

namespace App\Models;

use App\Support\Rooming;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One room in one hotel on one departure — §8.2.
 *
 * Knows how many beds it has and who is in it. Whether that arrangement is
 * *allowed* is {@see Rooming}'s question, deliberately kept
 * out of here: the rules are about a party, a departure date and a set of
 * ages, none of which a single room can see.
 */
class Room extends Model
{
    use HasFactory;

    protected $fillable = ['departure_hotel_id', 'label', 'capacity', 'gender', 'notes'];

    protected $casts = ['capacity' => 'integer'];

    public const MALE = 'male';

    public const FEMALE = 'female';

    /** Mixed on purpose: a husband, a wife and their children. */
    public const FAMILY = 'family';

    /** @var list<string> */
    public const GENDERS = [self::MALE, self::FEMALE, self::FAMILY];

    /** @return BelongsTo<DepartureHotel, $this> */
    public function hotel(): BelongsTo
    {
        return $this->belongsTo(DepartureHotel::class, 'departure_hotel_id');
    }

    /** @return HasMany<RoomAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    public function occupants(): Collection
    {
        return $this->assignments->map(fn (RoomAssignment $a): ?Traveller => $a->traveller)->filter()->values();
    }

    public function bedsFree(): int
    {
        return $this->capacity - $this->assignments->count();
    }

    public function isFull(): bool
    {
        return $this->bedsFree() <= 0;
    }

    /** Over capacity is a different thing from full, and worth saying. */
    public function isOverfull(): bool
    {
        return $this->bedsFree() < 0;
    }

    public function genderLabel(): string
    {
        return match ($this->gender) {
            self::MALE => 'Men',
            self::FEMALE => 'Women',
            self::FAMILY => 'Family',
            default => 'Not set',
        };
    }
}
