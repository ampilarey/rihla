<?php

namespace App\Models;

use App\Support\Brand;
use App\Support\Contrast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * A host's own page on Rihla — §16.8.
 *
 * Branding within a frame: the host chooses colours, a font, a layout and
 * their words; Rihla's header, footer and the trust line stay Rihla's.
 * No page builder and no custom CSS — enough to be a brand, not enough to
 * be a broken page.
 */
class HostPage extends Model
{
    use HasFactory, HasTranslations;

    public const STORY = 'story';

    public const GRID = 'grid';

    /** @var list<string> */
    public const LAYOUTS = [self::STORY, self::GRID];

    /** Sections a host switches on or off. They are toggled, not arranged. */
    public const SECTIONS = [
        'about' => 'Our story',
        'gallery' => 'Photographs',
        'map' => 'Map',
        'faq' => 'Questions and answers',
        'contact' => 'Contact',
    ];

    /**
     * Fonts a host may choose — each one the site already has, so a choice
     * never adds a request, a licence, or a page that renders in a
     * fallback. Inter is the site's own face; the other two are stacks
     * every device carries.
     *
     * @var array<string, array{label: string, stack: string}>
     */
    public const FONTS = [
        'inter' => ['label' => 'Inter (the site\'s own)', 'stack' => "'Inter', system-ui, sans-serif"],
        'serif' => ['label' => 'A classic serif', 'stack' => "Georgia, 'Times New Roman', serif"],
        'system' => ['label' => 'Your device\'s own', 'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"],
    ];

    /** @var list<string> */
    public array $translatable = ['tagline', 'about'];

    protected $fillable = [
        'layout', 'logo_path', 'cover_path', 'colour_primary', 'colour_accent', 'font',
        'tagline', 'about', 'sections', 'faq', 'whatsapp', 'instagram', 'facebook', 'website_url',
    ];

    protected $casts = [
        'sections' => 'array',
        'faq' => 'array',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'layout' => self::STORY,
        'font' => 'inter',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Every section is on until the host turns one off. */
    public function shows(string $section): bool
    {
        $sections = $this->sections;

        return ! is_array($sections) || in_array($section, $sections, true);
    }

    /** Headings and links: the host's colour, or Rihla's wine. Checked on save against the page ground. */
    public function primary(): string
    {
        return $this->colour_primary ?: Brand::WINE;
    }

    /** The button: the host's accent, or Rihla's wine. */
    public function accent(): string
    {
        return $this->colour_accent ?: Brand::WINE;
    }

    /**
     * White or ink on the accent — whichever reads, and the better of the
     * two when both do. The form refuses an accent neither reads on.
     */
    public function onAccent(): string
    {
        return self::textOn($this->accent());
    }

    public static function textOn(string $background): string
    {
        $white = Contrast::ratio('#FFFFFF', $background) ?? 0;
        $ink = Contrast::ratio(Brand::INK, $background) ?? 0;

        return $white >= $ink ? '#FFFFFF' : Brand::INK;
    }

    public function fontStack(): string
    {
        return (self::FONTS[$this->font] ?? self::FONTS['inter'])['stack'];
    }

    /**
     * Questions and answers in the reader's language, English where one has
     * not been written — and never an empty pair.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqFor(string $locale): array
    {
        $items = [];

        foreach ((array) $this->faq as $item) {
            $question = $item['question'][$locale] ?? null ?: ($item['question']['en'] ?? null);
            $answer = $item['answer'][$locale] ?? null ?: ($item['answer']['en'] ?? null);

            if (filled($question) && filled($answer)) {
                $items[] = ['question' => (string) $question, 'answer' => (string) $answer];
            }
        }

        return $items;
    }
}
