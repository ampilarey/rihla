<?php

namespace App\Models\Concerns;

use App\Exceptions\EditorialStandardNotMet;
use App\Models\ArticleReference;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The editorial standard §7.1 asks for, in one place.
 *
 * ## Why this is a trait and not two models that agree
 *
 * §7.1 requires sources and a named scholar on the Knowledge Centre. §7.2's
 * Ziyarah locations assert history, significance and etiquette — the same
 * kind of claim, made about the same subject, read by the same pilgrim. A
 * second copy of the rule is the copy that drifts: the first time somebody
 * relaxes one of these checks they will relax it in one file, and the other
 * feature will keep the stricter behaviour for a while and then quietly not.
 *
 * So the transitions live here and there is no path round them:
 *
 * - nothing reaches `approved` without at least one source;
 * - approval **names a scholar** a reader can see;
 * - nothing reaches `published` without having been approved;
 * - withdrawing requires a reason.
 *
 * Approval and publication stay separate acts by different people. A scholar
 * signing something off is a judgement about content; putting it on the site
 * is a decision about timing, and one button doing both makes the scholar the
 * publisher.
 *
 * A model using this needs the columns the migrations create for both
 * features: `status`, `written_by`, `reviewed_by`, `reviewed_at`,
 * `review_notes`, `published_at`, `withdrawn_reason`.
 */
trait EditorialGate
{
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

    public static function bootEditorialGate(): void
    {
        static::creating(function (self $record): void {
            $record->written_by ??= Auth::id();
        });
    }

    /**
     * What this is, in the words the screen uses.
     *
     * Overridden per model so a refusal reads like a sentence about the
     * thing the reader is looking at rather than about "the record".
     */
    protected function editorialSubject(): string
    {
        return 'page';
    }

    /**
     * The sources behind the claims.
     *
     * Polymorphic: an article and a location make the same kind of claim,
     * and two reference tables would be two copies of the grading rule.
     *
     * @return MorphMany<ArticleReference, $this>
     */
    public function references(): MorphMany
    {
        return $this->morphMany(ArticleReference::class, 'referenceable')->orderBy('sort_order');
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

    // ── The transitions ──────────────────────────────────────────────────

    /** Hand it to a scholar. */
    public function sendForReview(): void
    {
        $this->forceFill(['status' => self::IN_REVIEW])->save();
    }

    /**
     * A named scholar signs it off.
     *
     * The check is here rather than in a form request, because a form
     * request is one of several ways into this model and the other ways are
     * exactly where a style guide gets forgotten.
     */
    public function approve(Person $scholar, ?string $notes = null): void
    {
        if (($refusal = $this->whyNotApprovable()) !== null) {
            throw new EditorialStandardNotMet($refusal);
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
     * Separate from approval, and refuses without it.
     */
    public function publish(): void
    {
        if ($this->status !== self::APPROVED) {
            throw new EditorialStandardNotMet(
                'Only an approved '.$this->editorialSubject().' can be published. Editorial review before publish is non-negotiable for religious content (§6.4).',
            );
        }

        if ($this->reviewed_by === null) {
            throw new EditorialStandardNotMet('A '.$this->editorialSubject().' on the site must name the scholar who reviewed it.');
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
            throw new EditorialStandardNotMet('Withdrawing a '.$this->editorialSubject().' needs a reason. A page that vanished for no recorded cause is one nobody can explain later.');
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

    /**
     * Why this could not be approved right now, in the words the screen
     * shows — or null if it could.
     *
     * The single source of the refusal: {@see approve()} throws exactly
     * this sentence. A screen that explains one thing while the model
     * enforces another is how a rule gets quietly relaxed on one path.
     */
    public function whyNotApprovable(): ?string
    {
        if ($this->references()->count() === 0) {
            return 'No source yet. §7.1 requires every claim to carry a Qur\'an reference or a graded hadith, and a '.$this->editorialSubject().' about religion with no source is the thing that requirement exists to stop.';
        }

        return null;
    }

    /**
     * Sources a reader has to be warned about — §7.1.
     *
     * @return Collection<int, ArticleReference>
     */
    public function cautions(): Collection
    {
        return $this->references->filter(fn (ArticleReference $r): bool => $r->isCautionary());
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingAScholar(Builder $query): Builder
    {
        return $query->where('status', self::IN_REVIEW);
    }
}
