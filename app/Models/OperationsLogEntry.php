<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * What happened today, ordinarily — §8.3's daily operations log.
 *
 * Deliberately not an {@see Incident}. The coach being forty minutes late
 * and the hotel moving the group to the third floor belong here; things
 * that went *wrong* have a severity, an owner and a resolution, and keeping
 * them apart is what stops the incident list filling with weather.
 *
 * `happened_on` is the day it is about, separate from `created_at`. A log
 * written up the next morning is still about yesterday.
 */
class OperationsLogEntry extends Model
{
    use HasFactory;

    protected $table = 'operations_log_entries';

    protected $fillable = ['departure_id', 'happened_on', 'body'];

    protected $casts = ['happened_on' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->recorded_by ??= Auth::id();
            $entry->happened_on ??= now()->toDateString();
        });
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
