<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A night a room is not for sale — §15.4 (Phase 9.2).
 *
 * Distinct from a night that is sold. A blocked night has no stay against
 * it and never will: the partner took it back, the room is being repaired,
 * or a calendar feed said so. Keeping the two apart matters because a
 * blocked night is not freed when a hold lapses, and counting it as
 * occupancy would make `quantity` mean something different on those days.
 */
class BlockedDate extends Model
{
    use HasFactory;

    protected $fillable = ['room_type_id', 'date', 'source', 'note'];

    /** Rihla staff, in the admin. */
    public const ADMIN = 'admin';

    /** The guesthouse owner asked for it. */
    public const PARTNER = 'partner';

    /** An imported calendar feed. Nothing writes this yet. */
    public const ICAL = 'ical';

    /** @var list<string> */
    public const SOURCES = [self::ADMIN, self::PARTNER, self::ICAL];

    /** @var array<string, string> */
    protected $casts = ['date' => 'date'];

    /** @var array<string, mixed> */
    protected $attributes = ['source' => self::ADMIN];

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
