<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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
 *
 * ## The host — §16, ADR 0008
 *
 * §16 turns this record into the host of a marketplace and keeps its name
 * (a rename would touch every stays test for no behaviour change). The
 * states that decide whether a host may sell — {@see $verification},
 * {@see $status}, the settlement model, whether Rihla recommends them, and
 * whether the host *is* Rihla — are deliberately **not fillable**: a form
 * that forgot to strip them would let a host verify themselves. They are
 * set with `forceFill()` by the one action that owns each.
 */
class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'kind', 'island', 'contact_name', 'phone', 'whatsapp', 'email',
        'registration_number', 'registration_expires_on', 'registration_document_path',
        'pricing_model', 'commission_pct', 'green_tax_mode',
        'allotment_notes', 'contract_notes', 'is_active',
    ];

    // ── What kind of host — §16.5 ────────────────────────────────────────

    public const KIND_GUESTHOUSE = 'guesthouse';

    public const KIND_HOMESTAY = 'homestay';

    public const KIND_RENTAL_OWNER = 'rental_owner';

    public const KIND_AGENCY = 'agency';

    /** @var list<string> */
    public const KINDS = [self::KIND_GUESTHOUSE, self::KIND_HOMESTAY, self::KIND_RENTAL_OWNER, self::KIND_AGENCY];

    // ── Whether Rihla has checked them ───────────────────────────────────

    public const UNVERIFIED = 'unverified';

    /** Documents sent; a person at Rihla has not looked yet. */
    public const VERIFICATION_PENDING = 'pending';

    /** A person at Rihla checked the Ministry of Tourism registration. */
    public const VERIFIED = 'verified';

    public const REFUSED = 'refused';

    /** @var list<string> */
    public const VERIFICATIONS = [self::UNVERIFIED, self::VERIFICATION_PENDING, self::VERIFIED, self::REFUSED];

    // ── Whether they may sell ────────────────────────────────────────────

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    // ── Who holds the guest's money — ADR 0008 decision 3 ────────────────

    /** The guest's online payment is Rihla's commission; the rest is paid at the property. */
    public const COMMISSION_DEPOSIT = 'commission_deposit';

    /** Rihla takes everything and pays the host out against a statement. */
    public const FULL_COLLECTION = 'full_collection';

    /** @var list<string> */
    public const SETTLEMENT_MODELS = [self::COMMISSION_DEPOSIT, self::FULL_COLLECTION];

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
        'is_rihla' => 'boolean',
        'registration_expires_on' => 'date',
        'verified_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'recommended_at' => 'datetime',
    ];

    /**
     * The column defaults, carried in memory too — a partner just made
     * would otherwise read its verification as null, not `unverified`.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pricing_model' => self::NET_RATE,
        'green_tax_mode' => self::GREEN_TAX_AT_PROPERTY,
        'kind' => self::KIND_GUESTHOUSE,
        'verification' => self::UNVERIFIED,
        'status' => self::STATUS_PENDING,
        'settlement_model' => self::COMMISSION_DEPOSIT,
        'is_rihla' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $partner): void {
            if (blank($partner->slug)) {
                $partner->slug = self::mintSlug((string) $partner->name);
            }
        });
    }

    /**
     * A slug no other host holds.
     *
     * Two guesthouses on different islands share names often enough —
     * "Island Breeze" is on at least three — and the storefront URL has to
     * tell them apart without asking anybody to rename their business.
     */
    public static function mintSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'host';
        $slug = $base;

        for ($n = 2; self::where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class)->orderBy('sort_order');
    }

    /**
     * The host's own page — §16.8.
     *
     * @return HasOne<HostPage, $this>
     */
    public function page(): HasOne
    {
        return $this->hasOne(HostPage::class);
    }

    /**
     * May guests see this host at all? The host half of
     * {@see Property::scopeListable()}: active and checked by a person at
     * Rihla. A suspended or unverified host has no page, published or not.
     */
    public function isListed(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->verification === self::VERIFIED;
    }

    /**
     * Rihla's own host record, made by a migration — §16 Phase 12.5.
     *
     * Found by the flag, not the slug: `data:anonymise` rewrites every
     * partner's slug on the test server, and the flag is the only thing
     * that says which row is the company.
     */
    public static function rihla(): ?self
    {
        return self::where('is_rihla', true)->first();
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * The people who run this host, with their role — §16.6.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'host_memberships')
            ->withPivot(['role', 'accepted_at'])
            ->withTimestamps();
    }

    /** @return HasMany<HostMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(HostMembership::class);
    }

    /** @return HasMany<HostInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(HostInvitation::class);
    }

    /**
     * Every stay in this host's buildings — the host panel's bookings are
     * scoped through this (§16.6), because a stay has no `partner_id`.
     *
     * @return HasManyThrough<Stay, Property, $this>
     */
    public function stays(): HasManyThrough
    {
        return $this->hasManyThrough(Stay::class, Property::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /** @return HasMany<Payment, $this> */
    public function collectedPayments(): HasMany
    {
        return $this->hasMany(Payment::class);
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
