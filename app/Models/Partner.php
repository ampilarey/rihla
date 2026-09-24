<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The guesthouse owner — §15.4 (Phase 9.1).
 *
 * Rihla markets other people's buildings. A partner is the human on the
 * other end of that arrangement: who to ring, what was agreed, and how they
 * are paid. §15.2 decision 8 is deliberate — there is no partner login, and
 * confirmation is a Rihla staff member pressing a button that sends the
 * partner a message.
 *
 * Not translatable. A partner is an internal record; nothing on the public
 * site renders one.
 */
class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'island', 'contact_name', 'phone', 'whatsapp', 'email',
        'pricing_model', 'commission_pct', 'green_tax_mode',
        'allotment_notes', 'contract_notes', 'is_active',
    ];

    // ── How Rihla is paid ────────────────────────────────────────────────

    /** The partner quotes what they want; Rihla sells above it. */
    public const NET_RATE = 'net_rate';

    /** The partner's own rate is shown and Rihla takes a percentage. */
    public const COMMISSION = 'commission';

    /** @var list<string> */
    public const PRICING_MODELS = [self::NET_RATE, self::COMMISSION];

    // ── The green tax ────────────────────────────────────────────────────

    /**
     * The Maldives green tax is charged per guest per night. Whether it is
     * inside the quoted rate is a per-guesthouse answer, and getting it
     * wrong means a foreign visitor is asked for money at check-out that
     * they believed they had already paid.
     */
    public const GREEN_TAX_INCLUDED = 'included';

    public const GREEN_TAX_AT_PROPERTY = 'at_property';

    /** @var list<string> */
    public const GREEN_TAX_MODES = [self::GREEN_TAX_INCLUDED, self::GREEN_TAX_AT_PROPERTY];

    /** @var array<string, string> */
    protected $casts = [
        'commission_pct' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'pricing_model' => self::NET_RATE,
        'green_tax_mode' => self::GREEN_TAX_AT_PROPERTY,
    ];

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class)->orderBy('sort_order');
    }

    /** @param Builder<$this> $query */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether Rihla's margin on this partner comes from a markup or a cut.
     *
     * Asked as a question rather than compared to a string at each call
     * site, because §8.4's profitability work needs the same answer and a
     * second `=== 'commission'` written elsewhere is a second place to get
     * the spelling wrong.
     */
    public function isCommissionBased(): bool
    {
        return $this->pricing_model === self::COMMISSION;
    }
}
