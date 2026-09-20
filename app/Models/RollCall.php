<?php

namespace App\Models;

use App\Support\Rooming;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * A head count at one moment — §8.3.
 *
 * Not a daily register. A count is taken where somebody can actually be
 * left behind: boarding at Velana, off the coach in Madinah, back from the
 * Haram before a transfer. So there may be three in a day or none.
 *
 * ## An unmarked traveller is not present
 *
 * The whole point of this class. Nine of eleven marked is not "nine
 * present" — it is a count that has not been finished, and the two nobody
 * marked are exactly the two to go and look for. {@see unmarked()} is
 * therefore computed against the departure's confirmed travellers, never
 * against the rows that happen to exist.
 */
class RollCall extends Model
{
    use HasFactory;

    protected $fillable = ['departure_id', 'moment', 'taken_at', 'notes'];

    protected $casts = ['taken_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $rollCall): void {
            $rollCall->taken_by ??= Auth::id();
            $rollCall->taken_at ??= now();
        });
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    /**
     * The generic is declared because Larastan otherwise types the
     * collection as `Model`, and every closure that takes a
     * `RollCallMark` becomes an argument-type error in CI — which is
     * exactly what happened here.
     *
     * @return HasMany<RollCallMark, $this>
     */
    public function marks(): HasMany
    {
        return $this->hasMany(RollCallMark::class);
    }

    /**
     * Everybody this count is supposed to cover.
     *
     * The same set the rooming uses: travellers on a confirmed or completed
     * booking. A draft or a lapsed hold is not somebody who is on the
     * coach.
     *
     * @return Collection<int, Traveller>
     */
    public function expected(): Collection
    {
        // `departure_id` is NOT NULL with a cascade delete, so a roll call
        // without a departure cannot exist — the row would have gone with
        // it. PHPStan cannot read that from the schema, and the annotation
        // says which constraint is doing the work rather than leaving a
        // silent cast.
        /** @var Departure $departure */
        $departure = $this->departure;

        return Rooming::travellersOwedABed($departure);
    }

    /**
     * Nobody has said anything about these people.
     *
     * Computed by difference, not read from a column. A traveller added to
     * the departure after the count was taken shows up here, which is
     * correct: nobody has looked for them.
     *
     * @return Collection<int, Traveller>
     */
    public function unmarked(): Collection
    {
        $marked = $this->marks->pluck('traveller_id');

        return $this->expected()->reject(
            fn (Traveller $traveller): bool => $marked->contains($traveller->getKey()),
        )->values();
    }

    /**
     * The people to go and look for: unmarked, plus marked absent.
     *
     * Excused is deliberately not here. Somebody who stayed at the hotel
     * with a fever, with the leader's knowledge, is not missing, and
     * folding the two together means this cries wolf on every trip and
     * stops being read.
     *
     * @return Collection<int, Traveller>
     */
    public function unaccountedFor(): Collection
    {
        /** @var Collection<int, Traveller> $absent */
        $absent = collect();

        foreach ($this->marks as $mark) {
            if ($mark->state === RollCallMark::ABSENT && $mark->traveller !== null) {
                $absent->push($mark->traveller);
            }
        }

        /** @var Collection<int, Traveller> $all */
        $all = $this->unmarked()->concat($absent);

        return $all->unique('id')->values();
    }

    /** A count is finished when everybody expected has a mark. */
    public function isComplete(): bool
    {
        return $this->unmarked()->isEmpty();
    }

    /** Everybody is accounted for and nobody is missing. */
    public function isSettled(): bool
    {
        return $this->unaccountedFor()->isEmpty();
    }

    public function countIn(string $state): int
    {
        return $this->marks->where('state', $state)->count();
    }
}
