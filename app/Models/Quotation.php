<?php

namespace App\Models;

use App\Exceptions\EditorialStandardNotMet;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A priced offer sent to a lead — §8.1.
 *
 * ## It is replaced, never edited
 *
 * The point of keeping one is to be able to answer "what did we quote
 * them?" a fortnight later. Editing the price on a quotation somebody has
 * already been sent destroys exactly that, so {@see supersedeWith()} is the
 * only way to change a sent quotation, and it leaves both on the record
 * with a link between them.
 *
 * ## It expires, and the expiry is required
 *
 * A quotation with no end date is a price the operator is held to for ever
 * — through a currency move, a fuel surcharge and next season's hotel
 * rates. {@see isExpired()} is computed from the date rather than stored,
 * because a stored flag is wrong from the moment the day turns and right
 * again only when something remembers to run.
 *
 * ## Money is integer minor units
 *
 * [R-7], the same as everywhere else here. A float would be wrong by a
 * laari on some fraction of quotations and nobody would find out until a
 * reconciliation.
 */
/**
 * Larastan reads a `BelongsTo<Package, $this>` as returning `Package`,
 * and a date cast as a non-nullable Carbon. Both are nullable columns
 * here — an early quotation names no package, and `valid_until` is only
 * required on the way in — so without these it would have every `?->`
 * in this class removed as unnecessary.
 *
 * @property-read ?Package $package
 * @property-read ?Departure $departure
 * @property-read ?Booking $booking
 * @property-read ?Quotation $replacement
 * @property-read ?Carbon $valid_until
 */
class Quotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'enquiry_id', 'package_id', 'departure_id', 'party_size',
        'currency', 'total_minor', 'includes', 'excludes', 'valid_until',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
        'party_size' => 'integer',
        'total_minor' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    /** Written, not sent. Nobody outside the office has seen it. */
    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /** The date passed. Derived on read, never written by a job. */
    public const EXPIRED = 'expired';

    /** A newer quotation replaced it. Both stay on the record. */
    public const SUPERSEDED = 'superseded';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::SENT, self::ACCEPTED,
        self::DECLINED, self::EXPIRED, self::SUPERSEDED,
    ];

    /**
     * The commercial terms — the things a customer was actually told.
     *
     * @var list<string>
     */
    private const TERMS = [
        'enquiry_id', 'package_id', 'departure_id', 'party_size',
        'currency', 'total_minor', 'includes', 'excludes', 'valid_until',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $quotation): void {
            $quotation->created_by ??= Auth::id();
            $quotation->reference ??= $quotation->makeReference();
        });

        // The guarantee, on the model rather than on a hidden button.
        //
        // Hiding the edit action on a sent quotation is worth doing and is
        // not a guarantee: a console command, a seeder, or the next screen
        // somebody writes goes straight past it. Changing the price on a
        // quotation the customer has already been given destroys the one
        // reason to keep the record at all.
        static::updating(function (self $quotation): void {
            if ($quotation->getOriginal('status') === self::DRAFT) {
                return;
            }

            $changed = array_intersect(array_keys($quotation->getDirty()), self::TERMS);

            if ($changed !== []) {
                throw new EditorialStandardNotMet(
                    'This quotation has already gone to the customer, so what it says cannot be changed. Write a new one and supersede this — both stay on the record, which is how anybody can see later that the price moved.',
                );
            }
        });
    }

    /**
     * Human-readable and unique.
     *
     * The same shape as a booking reference, because the office reads both
     * off the same screens and two different formats is two things to
     * learn.
     */
    public function makeReference(): string
    {
        do {
            $reference = 'RIH-Q-'.now()->format('Y').'-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Quotation, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'superseded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }

    /** What one person is being asked for, which is the number they hear. */
    public function perPerson(): Money
    {
        $size = max(1, (int) $this->party_size);

        return Money::ofMinor(intdiv($this->total_minor, $size), $this->currency);
    }

    // ── The transitions ──────────────────────────────────────────────────

    public function markSent(): void
    {
        if ($this->status !== self::DRAFT) {
            throw new EditorialStandardNotMet('Only a draft can be sent. A quotation already out is replaced rather than re-sent, so the record keeps saying what they were told.');
        }

        $this->forceFill(['status' => self::SENT, 'sent_at' => now()])->save();
    }

    /**
     * They said yes.
     *
     * The booking is optional at this moment: somebody says yes on the
     * phone before anybody has taken a seat, and refusing to record that
     * until the booking exists means the acceptance is remembered by one
     * person.
     */
    public function accept(?Booking $booking = null): void
    {
        $this->guardDecidable();

        $this->forceFill([
            'status' => self::ACCEPTED,
            'decided_at' => now(),
            'booking_id' => $booking?->getKey() ?? $this->booking_id,
        ])->save();
    }

    public function decline(string $reason): void
    {
        if (trim($reason) === '') {
            throw new EditorialStandardNotMet('Say why they declined. "Too expensive" and "dates did not work" lead to different next offers, and a blank tells the next person nothing.');
        }

        $this->guardDecidable();

        $this->forceFill([
            'status' => self::DECLINED,
            'decided_at' => now(),
            'decline_reason' => $reason,
        ])->save();
    }

    /**
     * Replace this one with a new offer.
     *
     * The only way to change a quotation that has been sent. Both stay on
     * the record, linked, so a year later somebody can see that the price
     * moved and by how much.
     */
    public function supersedeWith(self $replacement): void
    {
        if ($replacement->is($this)) {
            throw new EditorialStandardNotMet('A quotation cannot replace itself.');
        }

        $this->forceFill([
            'status' => self::SUPERSEDED,
            'superseded_by' => $replacement->getKey(),
        ])->save();
    }

    private function guardDecidable(): void
    {
        if (! in_array($this->status, [self::DRAFT, self::SENT], true)) {
            throw new EditorialStandardNotMet('This quotation has already been settled one way or the other.');
        }
    }

    // ── Questions ────────────────────────────────────────────────────────

    /**
     * Past its date and still waiting on an answer.
     *
     * Derived, never stored. An accepted quotation does not expire — the
     * deal was done while it stood — and neither does a declined one.
     */
    public function isExpired(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SENT], true)
            && $this->valid_until !== null
            && $this->valid_until->endOfDay()->isPast();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SENT], true) && ! $this->isExpired();
    }

    /** Whole words, and the expiry is folded in where a reader would expect it. */
    public function statusLabel(): string
    {
        if ($this->isExpired()) {
            return 'Out of date';
        }

        return match ($this->status) {
            self::DRAFT => 'Not sent yet',
            self::SENT => 'With the customer',
            self::ACCEPTED => 'Accepted',
            self::DECLINED => 'Declined',
            self::EXPIRED => 'Out of date',
            self::SUPERSEDED => 'Replaced by a newer one',
            default => 'Unknown',
        };
    }

    /**
     * @param  Builder<Quotation>  $query
     * @return Builder<Quotation>
     */
    public function scopeAwaitingAnAnswer(Builder $query): Builder
    {
        return $query->whereIn('status', [self::DRAFT, self::SENT])
            ->whereDate('valid_until', '>=', now()->toDateString());
    }
}
