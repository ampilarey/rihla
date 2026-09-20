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

    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

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
        return Rooming::travellersOwedABed($this->departure);
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
        $absent = $this->marks
            ->where('state', RollCallMark::ABSENT)
            ->map(fn (RollCallMark $mark) => $mark->traveller)
            ->filter();

        return $this->unmarked()->concat($absent)->unique('id')->values();
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
