<?php

namespace App\Models;

use App\Casts\EncryptedIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person who slept in the room — §15.6 (Phase 11).
 *
 * Distinct from the customer, who is whoever paid. One person books a room
 * for four, and a register that assumes the payer is a guest is wrong the
 * first time somebody books for a relative.
 *
 * The identifier is encrypted in its column through
 * {@see EncryptedIdentifier}, which tolerates plaintext so that a scrubbed
 * test server still reads.
 */
class StayGuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'stay_id', 'full_name', 'nationality', 'date_of_birth',
        'id_type', 'id_number', 'is_lead',
    ];

    /** A foreign visitor. */
    public const PASSPORT = 'passport';

    /** A Maldivian national identity card — `A` and six digits. */
    public const NATIONAL_ID = 'national_id';

    /** @var list<string> */
    public const ID_TYPES = [self::PASSPORT, self::NATIONAL_ID];

    /** @var array<string, string> */
    protected $casts = [
        'date_of_birth' => 'date',
        'is_lead' => 'boolean',
        'id_number' => EncryptedIdentifier::class,
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['id_type' => self::PASSPORT];

    /** @return BelongsTo<Stay, $this> */
    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    /** @param Builder<$this> $query */
    public function scopeLead($query)
    {
        return $query->where('is_lead', true);
    }

    /**
     * How the register prints the identifier.
     *
     * Deliberately whole rather than masked. The register exists to be
     * produced to somebody with the authority to ask for it, and a masked
     * number is not an answer to that question — the protection is the
     * encryption in the column and the permission on the screen, not
     * asterisks in front of a member of staff who already has both.
     */
    public function identifier(): ?string
    {
        return $this->id_number;
    }

    public function identifierLabel(): string
    {
        return $this->id_type === self::NATIONAL_ID
            ? __('messages.National ID')
            : __('messages.Passport');
    }
}
