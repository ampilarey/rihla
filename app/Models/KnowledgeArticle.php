<?php

namespace App\Models;

use App\Exceptions\EditorialStandardNotMet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Spatie\Translatable\HasTranslations;

/**
 * An article in the Knowledge Centre — §7.1.
 *
 * ## The editorial standard is the state machine
 *
 * §7.1 asks for the standard to be "required fields, not a style guide
 * people forget". So the transitions enforce it and there is no path round
 * them:
 *
 * - nothing reaches `approved` without at least one reference;
 * - nothing reaches `approved` without a **named scholar**;
 * - nothing reaches `published` without having been approved.
 *
 * Approval and publication are separate acts by different people. A scholar
 * signing something off is a judgement about its content; putting it on the
 * site is an editorial decision about timing. One button doing both means
 * the scholar is also the publisher, which nobody asked for.
 *
 * ## Withdrawing is honest about what it does
 *
 * It takes the page down. It does not unsay it, and the reason is required
 * so that a year later somebody can find out why.
 */
class KnowledgeArticle extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = ['slug', 'title', 'summary', 'body', 'category'];

    /** @var array<int, string> */
    public array $translatable = ['title', 'summary', 'body'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    public const PLACE = 'place';

    public const EVENT = 'event';

    public const PERSON = 'person';

    public const DUA = 'dua';

    public const HISTORY = 'history';

    /** @var list<string> */
    public const CATEGORIES = [self::PLACE, self::EVENT, self::PERSON, self::DUA, self::HISTORY];

    /** Being written. Nobody outside the office sees it. */
    public const DRAFT = 'draft';

    /** Waiting on a scholar. */
    public const IN_REVIEW = 'in_review';

    /** A named scholar has signed it off. Not yet on the site. */
    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    /** Taken down, with a reason. */
    public const WITHDRAWN = 'withdrawn';

    /** @var list<string> */
    public const STATUSES = [
        self::DRAFT, self::IN_REVIEW, self::APPROVED, self::PUBLISHED, self::WITHDRAWN,
    ];

    /** @return HasMany<ArticleReference, $this> */
    public function references(): HasMany
    {
        return $this->hasMany(ArticleReference::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<User, $this> */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_by');
    }

    /**
     * The named scholar.
     *
     * A {@see Person}, not a User: §7.1 wants a name a reader can see, and
     * the scholar who reviews may never hold a staff login.
     *
     * @return BelongsTo<Person, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'reviewed_by');
    }

    protected static function booted(): void
    {
        static::creating(function (self $article): void {
            $article->written_by ??= Auth::id();
        });
    }

    // ── The transitions ──────────────────────────────────────────────────

    /** Hand it to a scholar. */
    public function sendForReview(): void
    {
        $this->forceFill(['status' => self::IN_REVIEW])->save();
    }

    /**
     * A named scholar signs it off.
     *
     * Both checks are here rather than in a form request, because a form
     * request is one of several ways into this model and the other ways are
     * exactly where a style guide gets forgotten.
     */
    public function approve(Person $scholar, ?string $notes = null): void
    {
        if ($this->references()->count() === 0) {
            throw new EditorialStandardNotMet(
                'This article has no source. §7.1 requires every claim to carry a Qur\'an reference or a graded hadith, and an article about religion with no source is the thing that requirement exists to stop.',
            );
        }

        $this->forceFill([
            'status' => self::APPROVED,
            'reviewed_by' => $scholar->getKey(),
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();
    }

    /**
     * Put it on the site.
     *
     * Separate from approval, and refuses without it. A scholar signing
     * something off is a judgement about content; publishing is a decision
     * about timing, and one button doing both makes the scholar the
     * publisher.
     */
    public function publish(): void
    {
        if ($this->status !== self::APPROVED) {
            throw new EditorialStandardNotMet(
                'Only an approved article can be published. Editorial review before publish is non-negotiable for religious content (§6.4).',
            );
        }

        if ($this->reviewed_by === null) {
            throw new EditorialStandardNotMet('An article on the site must name the scholar who reviewed it.');
        }

        $this->forceFill([
            'status' => self::PUBLISHED,
            'published_at' => $this->published_at ?? now(),
        ])->save();
    }

    /**
     * Take it down, and say why.
     *
     * Honest about what it does: the page goes, and anybody who read it has
     * read it. The reason is required so that a year later somebody can
     * find out what was wrong.
     */
    public function withdraw(string $reason): void
    {
        if (trim($reason) === '') {
            throw new EditorialStandardNotMet('Withdrawing an article needs a reason. A page that vanished for no recorded cause is one nobody can explain later.');
        }

        $this->forceFill([
            'status' => self::WITHDRAWN,
            'withdrawn_reason' => $reason,
        ])->save();
    }

    // ── Questions ────────────────────────────────────────────────────────

    public function isLive(): bool
    {
        return $this->status === self::PUBLISHED
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /** Whether it could be approved right now, and if not, why not. */
    public function whyNotApprovable(): ?string
    {
        if ($this->references()->count() === 0) {
            return 'No source yet. Add a Qur\'an reference or a graded hadith.';
        }

        return null;
    }

    /** References a reader has to be warned about — §7.1. */
    public function cautions(): Collection
    {
        return $this->references->filter(fn (ArticleReference $r): bool => $r->isCautionary());
    }

    /**
     * @param  Builder<KnowledgeArticle>  $query
     * @return Builder<KnowledgeArticle>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<KnowledgeArticle>  $query
     * @return Builder<KnowledgeArticle>
     */
    public function scopeAwaitingAScholar(Builder $query): Builder
    {
        return $query->where('status', self::IN_REVIEW);
    }

    /** Whole words, never a key built by concatenation. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => 'Draft',
            self::IN_REVIEW => 'With a scholar',
            self::APPROVED => 'Approved, not yet on the site',
            self::PUBLISHED => 'On the site',
            self::WITHDRAWN => 'Taken down',
            default => 'Unknown',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::PLACE => 'Place',
            self::EVENT => 'Event',
            self::PERSON => 'Person',
            self::DUA => 'Dua',
            self::HISTORY => 'History',
            default => 'Unknown',
        };
    }
}
