<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

/**
 * Something a customer needs to know.
 *
 * Not a queued message: there is no WhatsApp API, no SMTP and no SMS
 * provider, and this codebase already records that it will not pretend
 * otherwise. A notice appears on the Pilgrim Portal — which needs no
 * credentials — and puts the customer on a staff list with a prepared
 * WhatsApp link, so the conversation staff were going to have anyway takes
 * one tap.
 */
class Notice extends Model
{
    use HasFactory;

    protected $fillable = ['kind', 'headline', 'body'];

    protected $casts = [
        'seen_at' => 'datetime',
        'handled_at' => 'datetime',
    ];

    public const BOOKING_CONFIRMED = 'booking_confirmed';

    public const PAYMENT_RECORDED = 'payment_recorded';

    public const DOCUMENT_NEEDED = 'document_needed';

    public const DOCUMENT_REJECTED = 'document_rejected';

    public const VISA_ISSUED = 'visa_issued';

    public const PERMIT_ISSUED = 'permit_issued';

    public const DEPARTURE_SOON = 'departure_soon';

    // ── A stay, not a booking — §15.7 ────────────────────────────────────

    /** The guesthouse said yes. The deposit is now what stands in the way. */
    public const STAY_CONFIRMED = 'stay_confirmed';

    /**
     * A hold running out with the deposit unpaid.
     *
     * The one piece of news on this list with a clock on it: a hold that
     * lapses puts the room back on sale, and the customer finds out by
     * arriving at a page that no longer offers it.
     */
    public const DEPOSIT_DUE = 'deposit_due';

    /** The rest of the money, due the number of days the property sets. */
    public const BALANCE_DUE = 'balance_due';

    /** Check-in is close, and the directions are the useful part. */
    public const CHECK_IN_SOON = 'check_in_soon';

    /**
     * A closed list, because every one of these is raised from a record
     * that already exists. There is no notice for anything this system
     * cannot observe — which is what stops it inventing news.
     *
     * @var list<string>
     */
    public const KINDS = [
        self::BOOKING_CONFIRMED, self::PAYMENT_RECORDED,
        self::DOCUMENT_NEEDED, self::DOCUMENT_REJECTED,
        self::VISA_ISSUED, self::PERMIT_ISSUED, self::DEPARTURE_SOON,
        self::STAY_CONFIRMED, self::DEPOSIT_DUE, self::BALANCE_DUE, self::CHECK_IN_SOON,
    ];

    /**
     * The ones a customer must act on, as opposed to be pleased about.
     *
     * A confirmation needs no chasing; a passport request does. The staff
     * queue is built from these, because a list containing "we received
     * your payment" is one people stop reading.
     *
     * @var list<string>
     */
    public const NEEDS_THEM_TO_ACT = [
        self::DOCUMENT_NEEDED, self::DOCUMENT_REJECTED,
        // Both are money the customer still owes, and a deposit has a hold
        // running out behind it. "We are waiting to be paid" is exactly
        // the kind of thing this queue exists to put in front of somebody.
        self::DEPOSIT_DUE, self::BALANCE_DUE,
    ];

    /**
     * What this is about — a Booking or a Stay, since §15.7.
     *
     * @return MorphTo<Model, $this>
     */
    public function noticeable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The booking, when there is one.
     *
     * Kept because a great deal of this codebase reads `$notice->booking`
     * and every one of those places genuinely means a booking. It returns
     * null for a stay rather than something booking-shaped: a caller that
     * wants either should ask {@see noticeable()}, and one that assumes a
     * booking should get a null it can see rather than a stay it will
     * mis-handle.
     */
    public function getBookingAttribute(): ?Booking
    {
        $owner = $this->noticeable;

        return $owner instanceof Booking ? $owner : null;
    }

    /** The person to contact, whichever kind of thing this is about. */
    public function customer(): ?Customer
    {
        $owner = $this->noticeable;

        return $owner instanceof Booking || $owner instanceof Stay
            ? $owner->customer
            : null;
    }

    /**
     * The date the person is actually waiting for.
     *
     * A departure for a booking, a check-in for a stay. One question, two
     * columns in two tables — the staff queue asks it once rather than
     * carrying two mostly-empty columns.
     */
    public function travelsOn(): ?CarbonInterface
    {
        $owner = $this->noticeable;

        return match (true) {
            $owner instanceof Booking => $owner->departure?->date_start,
            $owner instanceof Stay => $owner->check_in,
            default => null,
        };
    }

    /** What staff would call it: `RIH-2026-0042`, or a stay's reference. */
    public function subjectReference(): ?string
    {
        $owner = $this->noticeable;

        return $owner instanceof Booking || $owner instanceof Stay
            ? $owner->reference
            : null;
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * Raise one, unless the same thing is already outstanding.
     *
     * Without this a nightly sweep stacks fourteen identical passport
     * reminders and the portal becomes a wall nobody reads. A notice that
     * has been handled can be raised again — the situation recurring is
     * news.
     */
    public static function raise(Model $about, string $kind, string $headline, ?string $body = null): ?self
    {
        $outstanding = self::where('noticeable_type', $about->getMorphClass())
            ->where('noticeable_id', $about->getKey())
            ->where('kind', $kind)
            ->whereNull('handled_at')
            ->exists();

        if ($outstanding) {
            return null;
        }

        $notice = self::make([
            'kind' => $kind,
            'headline' => $headline,
            'body' => $body,
        ]);

        $notice->noticeable()->associate($about);
        $notice->save();

        return $notice;
    }

    public function markSeen(): void
    {
        if ($this->seen_at === null) {
            $this->forceFill(['seen_at' => now()])->save();
        }
    }

    public function markHandled(?User $actor = null): void
    {
        $this->forceFill([
            'handled_at' => now(),
            'handled_by' => ($actor ?? Auth::user())?->getKey(),
        ])->save();
    }

    public function isHandled(): bool
    {
        return $this->handled_at !== null;
    }

    /**
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('handled_at');
    }

    /**
     * The queue staff actually work: outstanding, and needing the customer
     * to do something.
     *
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    public function scopeNeedsChasing(Builder $query): Builder
    {
        return $query->whereNull('handled_at')->whereIn('kind', self::NEEDS_THEM_TO_ACT);
    }

    /**
     * A WhatsApp conversation, opened at the right place.
     *
     * The bridge while there is no messaging API: staff tap this and the
     * conversation they were going to have anyway starts with the message
     * already written. The same pattern as the waitlist claim link, for the
     * same reason.
     *
     * Returns null without a number — an "open WhatsApp" button that opens
     * nothing is worse than no button.
     */
    public function whatsappUrl(): ?string
    {
        $number = $this->customer()?->phone;

        if (blank($number)) {
            return null;
        }

        $text = $this->headline.($this->body ? "\n\n".$this->body : '');

        return 'https://wa.me/'.preg_replace('/\D+/', '', (string) $number).'?text='.rawurlencode($text);
    }

    /** Whole words, never a key built by concatenation. */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::BOOKING_CONFIRMED => 'Booking confirmed',
            self::PAYMENT_RECORDED => 'Payment recorded',
            self::DOCUMENT_NEEDED => 'Document needed',
            self::DOCUMENT_REJECTED => 'Document sent back',
            self::VISA_ISSUED => 'Visa issued',
            self::PERMIT_ISSUED => 'Umrah permit issued',
            self::DEPARTURE_SOON => 'Departure coming up',
            self::STAY_CONFIRMED => 'Guesthouse confirmed',
            self::DEPOSIT_DUE => 'Deposit due',
            self::BALANCE_DUE => 'Balance due',
            self::CHECK_IN_SOON => 'Check-in coming up',
            default => 'Unknown',
        };
    }
}
