<?php

namespace App\Models;

use App\Support\CustomerDossier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The person who books and pays.
 *
 * Separate from `travellers` because in this market one person routinely
 * books for six — a mother, three children and two grandparents — and only
 * the payer will ever have a login. Separate from `users` because most
 * customers are taken down over the phone or WhatsApp by booking staff and
 * never create an account at all; `user_id` is filled in later, if and when
 * a Pilgrim Portal invitation is accepted.
 */
/**
 * @property-read ?Customer $referrer
 */
class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'national_id', 'address', 'notes',
        // §8.1's referral tracking. Mass-assignable because the admin form
        // writes them through update(): left out of this list they are
        // dropped without a word, which is the trap AGENTS.md records.
        'referred_by_customer_id', 'referral_source',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who sent them — §8.1's referral tracking.
     *
     * A customer we already have, not free text: "Ahmed" is not a person
     * anybody can find later, and the point of tracking a referral is to
     * be able to thank the person who made it.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    /** @return HasMany<Customer, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Customer::class, 'referred_by_customer_id');
    }

    /**
     * Facts about this person that outlive any one lead.
     *
     * @return HasMany<CustomerTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(CustomerTag::class)->orderBy('tag');
    }

    /** @return HasMany<Enquiry, $this> */
    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }

    /** @return MorphMany<CrmTask, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(CrmTask::class, 'about')->orderBy('due_on');
    }

    /**
     * Bookings that mean this person actually went.
     *
     * Confirmed or completed — not `active()`, which also takes drafts and
     * holds. A draft is somebody who thought about it.
     *
     * @var list<string>
     */
    public const TRAVELLED_ON = [Booking::CONFIRMED, Booking::COMPLETED];

    /**
     * The last journey that has actually departed.
     *
     * Used by the re-engagement list (§8.1): somebody whose last Umrah was
     * eighteen months ago is somebody to ring, and somebody who flies next
     * month is not.
     *
     * The status filter here and the one in
     * {@see CustomerDossier::journeysTaken()} are the same
     * list on purpose. They were not, once: the customer page said "has not
     * travelled with us yet" directly above "last travelled 14 months ago",
     * because one counted confirmed bookings and the other counted every
     * live one.
     */
    public function lastDeparted(): ?Departure
    {
        return Departure::query()
            ->whereIn('id', $this->bookings()->whereIn('status', self::TRAVELLED_ON)->pluck('departure_id'))
            ->whereDate('date_start', '<', now()->toDateString())
            ->orderByDesc('date_start')
            ->first();
    }

    /** @return HasMany<Traveller, $this> */
    public function travellers(): HasMany
    {
        return $this->hasMany(Traveller::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
