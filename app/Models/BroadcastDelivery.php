<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person, one channel, one outcome.
 *
 * There is no `sent` status, deliberately. Either the message reached
 * somewhere a person will see it, or it did not — a row claiming the middle
 * is how "we told everybody" becomes a thing nobody can check.
 */
class BroadcastDelivery extends Model
{
    use HasFactory;

    protected $fillable = ['emergency_broadcast_id', 'booking_id', 'channel', 'status', 'detail'];

    /** It is somewhere a person will see it. */
    public const DELIVERED = 'delivered';

    /** This host cannot send on that channel at all. */
    public const UNAVAILABLE = 'unavailable';

    /** It could have, and did not. */
    public const FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [self::DELIVERED, self::UNAVAILABLE, self::FAILED];

    /** @return BelongsTo<EmergencyBroadcast, $this> */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(EmergencyBroadcast::class, 'emergency_broadcast_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** Whole words, never a key built by concatenation. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DELIVERED => 'Delivered',
            self::UNAVAILABLE => 'Channel not set up',
            self::FAILED => 'Failed',
            default => 'Unknown',
        };
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'portal' => 'On their portal page',
            'email' => 'Email',
            'sms' => 'SMS',
            default => 'Unknown',
        };
    }
}
