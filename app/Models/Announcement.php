<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Something staff want the group and their families to know — §6.2.
 *
 * Group-level by definition: an announcement is about the departure, never
 * about one person. That is what makes it safe to show a family without
 * asking anybody's permission, and it is why there is no `traveller_id`
 * here and should not be one.
 */
class Announcement extends Model
{
    use HasFactory;

    protected $fillable = ['departure_id', 'headline', 'body', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $announcement): void {
            $announcement->written_by ??= Auth::id();
        });
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by');
    }

    /**
     * Published, and published *already*.
     *
     * `whereNotNull` alone would put a post-dated announcement on a
     * family's screen the moment somebody saved it, which is the one thing
     * scheduling it was for.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isLive(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }
}
