<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message about a stay — §16.11. Plain text, from the guest, the host
 * or Rihla; read when anybody on another side opens the conversation.
 */
class StayMessage extends Model
{
    public const GUEST = 'guest';

    public const HOST = 'host';

    public const RIHLA = 'rihla';

    /** @var list<string> */
    public const SENDERS = [self::GUEST, self::HOST, self::RIHLA];

    /** Plain text, and a length that is a message rather than a document. */
    public const MAX_LENGTH = 2000;

    protected $fillable = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @return BelongsTo<User, $this> */
    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /**
     * Unread by `$side`: sent by somebody else, not yet opened.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnreadBy(Builder $query, string $side): Builder
    {
        return $query->where('sender', '!=', $side)->whereNull('read_at');
    }

    public function senderLabel(): string
    {
        return match ($this->sender) {
            self::GUEST => __('messages.You'),
            self::HOST => $this->stay?->property?->getTranslation('name', app()->getLocale()) ?? __('messages.The host'),
            default => 'Rihla Travels',
        };
    }
}
