<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Telling a whole departure at once — §6.5.
 *
 * Sending is its own act and it cannot be undone, so `sent_at` is separate
 * from `created_at` and a draft is a draft until somebody means it.
 */
class EmergencyBroadcast extends Model
{
    use HasFactory;

    protected $fillable = ['departure_id', 'incident_id', 'headline', 'body'];

    protected $casts = ['sent_at' => 'datetime'];

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** @return HasMany<BroadcastDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(BroadcastDelivery::class);
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * @param  Builder<EmergencyBroadcast>  $query
     * @return Builder<EmergencyBroadcast>
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at');
    }

    /** How many people this actually reached, not how many were tried. */
    public function reached(): int
    {
        return $this->deliveries
            ->where('status', BroadcastDelivery::DELIVERED)
            ->pluck('booking_id')
            ->unique()
            ->count();
    }
}
