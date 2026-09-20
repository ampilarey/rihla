<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One person, at one count.
 *
 * Three states, and `excused` is not a kind of `absent`: somebody who
 * stayed at the hotel with a fever, with the leader's knowledge, is not
 * missing. There is no fourth state for "not looked at" — that is the
 * *absence* of a row, because a default of "present" on an unfinished count
 * is how somebody gets left at an airport.
 */
class RollCallMark extends Model
{
    use HasFactory;

    protected $fillable = ['roll_call_id', 'traveller_id', 'state', 'note'];

    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    /** Not there, and that is known and fine. */
    public const EXCUSED = 'excused';

    /** @var list<string> */
    public const STATES = [self::PRESENT, self::ABSENT, self::EXCUSED];

    protected static function booted(): void
    {
        static::creating(function (self $mark): void {
            $mark->marked_by ??= Auth::id();
        });
    }

    public function rollCall(): BelongsTo
    {
        return $this->belongsTo(RollCall::class);
    }

    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /** Whole words, never a key built by concatenation. */
    public function stateLabel(): string
    {
        return match ($this->state) {
            self::PRESENT => 'Present',
            self::ABSENT => 'Absent',
            self::EXCUSED => 'Excused',
            default => 'Unknown',
        };
    }
}
