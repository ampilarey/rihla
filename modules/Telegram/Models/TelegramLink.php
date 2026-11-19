<?php

namespace Modules\Telegram\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff account bound to one Telegram chat. The bot acts as this person
 * in that chat — with their permissions, in the audit trail as them.
 *
 * @property int $user_id
 * @property int $chat_id
 * @property string|null $telegram_username
 * @property string|null $telegram_name
 * @property array<string, mixed>|null $pending
 * @property Carbon $linked_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 */
class TelegramLink extends Model
{
    use BelongsToCompany;

    protected $fillable = ['user_id', 'chat_id', 'telegram_username', 'telegram_name', 'linked_at', 'revoked_at', 'last_seen_at', 'pending'];

    protected $casts = [
        'chat_id' => 'integer',
        'pending' => 'array',
        'linked_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<TelegramLink>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * A root record: it takes the current company.
     *
     * @return list<string>
     */
    protected function companyParents(): array
    {
        return [];
    }
}
