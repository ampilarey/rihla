<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One delivery from Telegram. The unique `update_id` is what makes a
 * retried delivery answer once; `kind` and `command` are what the office
 * reads when asking what the bot has been doing.
 *
 * @property int $update_id
 * @property int|null $chat_id
 * @property string|null $kind
 * @property string|null $command
 * @property Carbon $received_at
 * @property Carbon|null $handled_at
 * @property string|null $error
 */
class TelegramUpdate extends Model
{
    public $timestamps = false;

    protected $fillable = ['update_id', 'chat_id', 'kind', 'command', 'received_at', 'handled_at', 'error'];

    protected $casts = ['received_at' => 'datetime', 'handled_at' => 'datetime'];
}
