<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time code shown in the panel and sent to the bot as /start CODE.
 * Hashed at rest, like a password reset token.
 *
 * @property int $user_id
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class TelegramLinkCode extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'expires_at', 'used_at'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
