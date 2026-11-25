<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One notice's e-mail to its customer: where it went, when, or why not yet.
 *
 * @property int $notice_id
 * @property string $address
 * @property Carbon|null $sent_at
 * @property int $attempts
 * @property string|null $last_error
 */
class NoticeEmail extends Model
{
    /** After this many failures a notice is left to WhatsApp. */
    public const MAX_ATTEMPTS = 3;

    protected $fillable = [];

    protected $casts = ['sent_at' => 'datetime', 'attempts' => 'integer'];

    /** @return BelongsTo<Notice, $this> */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
