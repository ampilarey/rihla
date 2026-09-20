<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'national_id', 'address', 'notes',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
