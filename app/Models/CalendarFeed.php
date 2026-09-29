<?php

namespace App\Models;

use App\Casts\EncryptedIdentifier;
use App\Services\Stays\CalendarImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another site's calendar for one room, imported — §16 Phase 16.
 *
 * {@see CalendarImport} turns it into blocked nights.
 */
class CalendarFeed extends Model
{
    protected $fillable = ['room_type_id', 'label', 'url'];

    /** @var array<string, string> */
    protected $casts = [
        'url' => EncryptedIdentifier::class,
        'last_synced_at' => 'datetime',
        'nights_blocked' => 'integer',
    ];

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** Enough of the link to recognise it, never enough to use it. */
    public function maskedUrl(): string
    {
        $host = parse_url((string) $this->url, PHP_URL_HOST);

        return is_string($host) ? $host.'/…' : '—';
    }
}
