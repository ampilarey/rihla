<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room with a door — §16.5, §16 Phase 12.3.
 *
 * `room_types.quantity` is what is **sold**: "three Deluxe Doubles". A unit
 * is what **exists**: Room 4, Room 5 and Room 7, each of which is clean or
 * not and has somebody in it or not. Reception works in units; the
 * calendar works in quantities.
 *
 * The two are deliberately not forced to agree. A room under repair is a
 * unit that exists and is not sold; a host setting up the panel has sold
 * rooms before entering any units at all. Refusing a save because the
 * counts differ would stop both, so the host panel warns instead (§16.5).
 */
class PropertyUnit extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id', 'room_type_id', 'label', 'floor', 'housekeeping', 'is_active', 'sort_order',
    ];

    public const CLEAN = 'clean';

    public const DIRTY = 'dirty';

    /** Cleaned, and somebody has checked. */
    public const INSPECTED = 'inspected';

    /** @var list<string> */
    public const HOUSEKEEPING = [self::CLEAN, self::DIRTY, self::INSPECTED];

    /** @var array<string, string> */
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /** @var array<string, mixed> */
    protected $attributes = ['housekeeping' => self::CLEAN, 'is_active' => true, 'sort_order' => 0];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return HasMany<Stay, $this> */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class, 'unit_id');
    }
}
