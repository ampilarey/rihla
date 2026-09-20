<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $fillable = ['booking_id', 'kind', 'headline', 'body'];

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
    public const NEEDS_THEM_TO_ACT = [self::DOCUMENT_NEEDED, self::DOCUMENT_REJECTED];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
    public static function raise(Booking $booking, string $kind, string $headline, ?string $body = null): ?self
    {
        $outstanding = self::where('booking_id', $booking->getKey())
            ->where('kind', $kind)
            ->whereNull('handled_at')
            ->exists();

        if ($outstanding) {
            return null;
        }

        return self::create([
            'booking_id' => $booking->getKey(),
            'kind' => $kind,
            'headline' => $headline,
            'body' => $body,
        ]);
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
        $number = $this->booking->customer->phone ?? null;

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
            default => 'Unknown',
        };
    }
}
