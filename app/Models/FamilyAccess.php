<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way in for a family at home — §6.2.
 *
 * A separate model from {@see PortalAccess} rather than a flag on it,
 * because a family token must never be accepted anywhere a pilgrim token
 * is. One table with a `kind` column is one forgotten `where` away from a
 * mother-in-law reading a passport number.
 *
 * The pilgrim mints it and the pilgrim revokes it. §6.2 is explicit that
 * the controls are theirs, so staff cannot issue one on their behalf.
 */
class FamilyAccess extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'token_hash', 'label', 'shares_attendance', 'expires_at'];

    protected $casts = [
        'shares_attendance' => 'boolean',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'uses' => 'integer',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Why it is not usable, for a person rather than for a log. */
    public function whyNot(): ?string
    {
        if ($this->revoked_at !== null) {
            return 'That link has been turned off by the person travelling.';
        }

        if ($this->expires_at->isPast()) {
            return 'That link has expired. Ask the person travelling for a new one.';
        }

        return null;
    }
}
