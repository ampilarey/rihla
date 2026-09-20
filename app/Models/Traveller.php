<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who travels. May have no login, and usually does not.
 *
 * `full_name` is one field on purpose: a large share of Maldivian names do
 * not divide into given and family names the way a two-field form assumes,
 * and forcing them to produces passports that do not match bookings.
 *
 * The passport *number* is a column here because the visa workflow needs it
 * as a field. The passport *scan* is never stored on this row — it is a
 * versioned document in the wallet (plan §5.5, [R-8]), on a private disk,
 * reachable only through short-lived signed URLs, with every download
 * audited.
 */
class Traveller extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'full_name', 'date_of_birth', 'gender', 'relationship',
        'nationality', 'passport_number', 'passport_issuing_country', 'passport_expiry',
        'medical_notes', 'emergency_contact_name', 'emergency_contact_phone',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'passport_expiry' => 'date',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'nationality' => 'MV',
    ];

    public const MALE = 'male';

    public const FEMALE = 'female';

    /** @var list<string> */
    public const GENDERS = [self::MALE, self::FEMALE];

    /**
     * Relationship to the person paying. Free-form in the database; this
     * list is what the form offers. 'other' is a real answer, not a
     * fallback — a short enum would push genuine cases into it and hide the
     * ones operations needs to look at for mahram rules.
     *
     * @var list<string>
     */
    public const RELATIONSHIPS = ['self', 'spouse', 'child', 'parent', 'sibling', 'relative', 'other'];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<BookingTraveller, $this> */
    public function bookingTravellers(): HasMany
    {
        return $this->hasMany(BookingTraveller::class);
    }

    /**
     * Age on a given date — the departure date, when it matters.
     *
     * Takes the date rather than using today's, because every rule that
     * cares (child pricing, the mahram requirement, a minor travelling) is
     * about age *at travel*, and a fourteen-year-old who turns fifteen in
     * the air is a different booking from one who does not.
     */
    public function ageOn(\DateTimeInterface $date): ?int
    {
        // Cast, because Carbon 3 returns a float and returning 12.9 from an
        // int method is a lossy implicit conversion PHP only warns about.
        return $this->date_of_birth === null
            ? null
            : (int) $this->date_of_birth->diffInYears($date);
    }
}
