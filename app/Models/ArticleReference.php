<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source for a claim — §7.1's "every claim carries a source".
 *
 * ## A hadith without a grading cannot exist
 *
 * Enforced in {@see booted()}, not asked for in a form. §7.1 says to build
 * the editorial standard in as required fields rather than a style guide
 * people forget, and a validation rule on one Filament form is a style
 * guide with extra steps: it is bypassed by a seeder, a console command, or
 * the next screen somebody writes.
 *
 * A Qur'an reference has no grading and is not given one. Forcing a value
 * there would mean inventing a category that does not exist.
 *
 * ## Weak and fabricated narrations are kept, and labelled
 *
 * Deleting them is not the goal. §7.2 makes the point directly about
 * misconceptions: naming a weak narration as weak is what stops a pilgrim
 * repeating it. So `daif` and `mawdu` are valid values that carry a visible
 * label onto the public page, and {@see isCautionary()} is what the view
 * asks.
 */
class ArticleReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_article_id', 'kind', 'citation', 'grading', 'note', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    public const QURAN = 'quran';

    public const HADITH = 'hadith';

    /** @var list<string> */
    public const KINDS = [self::QURAN, self::HADITH];

    public const SAHIH = 'sahih';

    public const HASAN = 'hasan';

    public const DAIF = 'daif';

    public const MAWDU = 'mawdu';

    public const DISPUTED = 'disputed';

    /** @var list<string> */
    public const GRADINGS = [self::SAHIH, self::HASAN, self::DAIF, self::MAWDU, self::DISPUTED];

    /**
     * The gradings that must be shown to a reader as a caution.
     *
     * §7.1: "disputed/weak narrations are labelled as such."
     *
     * @var list<string>
     */
    public const CAUTIONARY = [self::DAIF, self::MAWDU, self::DISPUTED];

    protected static function booted(): void
    {
        static::saving(function (self $reference): void {
            if ($reference->kind === self::HADITH && blank($reference->grading)) {
                throw new \InvalidArgumentException(
                    'A hadith reference must carry a grading. An ungraded narration on a page about religion is the thing this field exists to prevent.',
                );
            }

            // A Qur'an verse is not graded, and a grading on one would be a
            // category error a reader would take seriously.
            if ($reference->kind === self::QURAN) {
                $reference->grading = null;
            }
        });
    }

    /** @return BelongsTo<KnowledgeArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    public function isCautionary(): bool
    {
        return in_array($this->grading, self::CAUTIONARY, true);
    }

    /**
     * Whole words, never a key built by concatenation.
     *
     * These reach a reader, so they say what the grading means rather than
     * only naming it: somebody who does not know what `da'if` is should
     * still understand not to repeat the narration.
     */
    public function gradingLabel(): string
    {
        return match ($this->grading) {
            self::SAHIH => 'Sahih — authentic',
            self::HASAN => 'Hasan — good',
            self::DAIF => "Da'if — weak",
            self::MAWDU => "Mawdu' — fabricated",
            self::DISPUTED => 'Disputed among scholars',
            default => '',
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::QURAN => "Qur'an",
            self::HADITH => 'Hadith',
            default => 'Unknown',
        };
    }
}
