<?php

namespace Modules\Transport\Models;

use App\Models\Island;
use App\Models\Partner;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operator's route from one island to another, with its fares —
 * docs/TRANSPORT_PLAN.md. One adult fare and an optional child fare (a child
 * pays the adult fare when none is set).
 */
class BoatRoute extends Model
{
    protected $fillable = ['partner_id', 'origin_island_id', 'destination_island_id', 'duration_minutes', 'currency', 'adult_fare_minor', 'child_fare_minor', 'is_active', 'notes'];

    protected $casts = [
        'duration_minutes' => 'integer',
        'adult_fare_minor' => 'integer',
        'child_fare_minor' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** @return BelongsTo<Island, $this> */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'origin_island_id');
    }

    /** @return BelongsTo<Island, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'destination_island_id');
    }

    /** @return HasMany<Sailing, $this> */
    public function sailings(): HasMany
    {
        return $this->hasMany(Sailing::class);
    }

    public function fare(bool $child): int
    {
        return $child && $this->child_fare_minor !== null ? $this->child_fare_minor : $this->adult_fare_minor;
    }

    public function money(int $minor): Money
    {
        return Money::ofMinor($minor, $this->currency);
    }

    /** "Malé → Fulidhoo". */
    public function label(): string
    {
        return ($this->origin->name ?? '?').' → '.($this->destination->name ?? '?');
    }
}
