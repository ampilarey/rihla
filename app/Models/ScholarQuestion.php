<?php

namespace App\Models;

use App\Exceptions\EditorialStandardNotMet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A question somebody asked, and what a named scholar said back — §6.4.
 *
 * ## It is a private message unless the asker said otherwise
 *
 * People ask about a rite they think they got wrong, a marriage, an
 * illness, money. {@see publish()} refuses without `may_publish`, which is
 * consent given at the moment of asking and never assumed afterwards.
 * Nothing in the office can grant it on somebody's behalf.
 *
 * ## Every ending is an ending
 *
 * A question is answered or declined, and declining needs a reason.
 * "We are not the right people to answer this" is honest and sometimes the
 * only correct answer; leaving it in the queue for ever is not, and the
 * queue counts exactly what nobody has dealt with.
 *
 * ## An answer names the person who gave it
 *
 * The same rule as every other religious claim in this codebase, and it is
 * the reason {@see answer()} takes a {@see Person} rather than reading the
 * logged-in user: the scholar who answers may never hold a staff login.
 */
/**
 * @property-read ?Person $scholar
 * @property-read ?Booking $booking
 */
class ScholarQuestion extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'traveller_id', 'body', 'locale', 'may_publish'];

    protected $casts = [
        'may_publish' => 'boolean',
        'answered_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::ASKED];

    /** Waiting on somebody. */
    public const ASKED = 'asked';

    public const ANSWERED = 'answered';

    /** Nobody here is the right person to answer it, and it says why. */
    public const DECLINED = 'declined';

    /** @var list<string> */
    public const STATUSES = [self::ASKED, self::ANSWERED, self::DECLINED];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Traveller, $this> */
    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function scholar(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'answered_by');
    }

    /**
     * Sources behind the answer.
     *
     * The same polymorphic table, and the same grading rule. An answer to
     * "did I do my tawaf properly" carries a source for the same reason an
     * article does — more so, because it is about something the person has
     * already done.
     *
     * @return MorphMany<ArticleReference, $this>
     */
    public function references(): MorphMany
    {
        return $this->morphMany(ArticleReference::class, 'referenceable')->orderBy('sort_order');
    }

    // ── The endings ──────────────────────────────────────────────────────

    /** A named scholar answers. */
    public function answerWith(Person $scholar, string $answer): void
    {
        if (trim($answer) === '') {
            throw new EditorialStandardNotMet('An answer cannot be blank. If there is nothing to say, decline it and say why — a question left open is not an answer.');
        }

        $this->forceFill([
            'status' => self::ANSWERED,
            'answered_by' => $scholar->getKey(),
            'answer' => $answer,
            'answered_at' => now(),
            'declined_reason' => null,
        ])->save();
    }

    /**
     * Nobody here is the right person to answer it.
     *
     * An honest ending, and the reason is required: the pilgrim is owed a
     * sentence they can act on — who to ask instead — rather than silence.
     */
    public function decline(string $reason): void
    {
        if (trim($reason) === '') {
            throw new EditorialStandardNotMet('Declining a question needs a reason. The person who asked is owed a sentence they can act on, not silence.');
        }

        $this->forceFill([
            'status' => self::DECLINED,
            'declined_reason' => $reason,
        ])->save();
    }

    /**
     * Put the question and its answer where other people can read it.
     *
     * Refuses without the asker's consent, and refuses without an answer.
     * Consent is given once, at the moment of asking; nothing in the office
     * can grant it on somebody's behalf, which is why there is no argument
     * to this method.
     */
    public function publish(): void
    {
        if (! $this->may_publish) {
            throw new EditorialStandardNotMet('The person who asked this did not agree to it being shown to anybody else. That is theirs to give and it cannot be granted here.');
        }

        if ($this->status !== self::ANSWERED) {
            throw new EditorialStandardNotMet('Only an answered question can be published.');
        }

        $this->forceFill(['published_at' => $this->published_at ?? now()])->save();
    }

    public function unpublish(): void
    {
        $this->forceFill(['published_at' => null])->save();
    }

    // ── Questions ────────────────────────────────────────────────────────

    public function isWaiting(): bool
    {
        return $this->status === self::ASKED;
    }

    public function isPublic(): bool
    {
        return $this->may_publish
            && $this->status === self::ANSWERED
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /** Whole words, and they say what the asker would want to know. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ASKED => 'Waiting for a scholar',
            self::ANSWERED => 'Answered',
            self::DECLINED => 'We could not answer this one',
            default => 'Unknown',
        };
    }

    /**
     * @param  Builder<ScholarQuestion>  $query
     * @return Builder<ScholarQuestion>
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::ASKED);
    }

    /**
     * @param  Builder<ScholarQuestion>  $query
     * @return Builder<ScholarQuestion>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('may_publish', true)
            ->where('status', self::ANSWERED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
