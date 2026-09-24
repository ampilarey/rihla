<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seasonal price for a room type — §15.4 (Phase 9.2).
 *
 * Both ends inclusive: a season written 1 December to 31 December covers
 * the 31st. That differs from a *stay*, whose check-out night is not slept,
 * and the difference is deliberate — a guesthouse owner typing a season
 * means both dates, and a guest booking the 3rd to the 5th means two
 * nights. Conflating them charges the last night of a season at the wrong
 * rate.
 */
class Rate extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_type_id', 'starts_on', 'ends_on', 'rate_minor', 'min_nights',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'rate_minor' => 'integer',
        'min_nights' => 'integer',
    ];

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Seasons that could price any night in a half-open stay.
     *
     * The range asked about is `[$from, $until)` — check-out is not slept —
     * so a season starting on the check-out date prices nothing and is
     * excluded. Getting that wrong pulls in a season the guest never
     * occupies, which is invisible until the one night it changes the
     * total.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCovering($query, CarbonInterface $from, CarbonInterface $until)
    {
        return $query
            ->whereDate('starts_on', '<', $until->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString());
    }

    public function rate(string $currency = 'USD'): Money
    {
        return Money::ofMinor($this->rate_minor, $currency);
    }

    /** Does this season price the given night? Both ends inclusive. */
    public function covers(CarbonInterface $night): bool
    {
        return $night->toDateString() >= $this->starts_on->toDateString()
            && $night->toDateString() <= $this->ends_on->toDateString();
    }
}
