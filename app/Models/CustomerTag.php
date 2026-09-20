<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A fact about a person that outlives every lead they will ever be —
 * §8.1's tags and preferences.
 *
 * "Travels with her mother", "prefers Ramadan", "wheelchair at the airport".
 * On the customer rather than the enquiry, because a tag on an enquiry is
 * lost the moment the enquiry closes, which is exactly when it starts being
 * useful.
 *
 * Folded to lower case on the way in, because "Ramadan" and "ramadan"
 * typed by two people is the same fact and a list showing both is a list
 * nobody trusts.
 */
class CustomerTag extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'tag'];

    protected static function booted(): void
    {
        static::saving(function (self $tag): void {
            $tag->tag = mb_strtolower(trim((string) $tag->tag));
            $tag->created_by ??= Auth::id();
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
