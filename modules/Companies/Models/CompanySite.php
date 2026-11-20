<?php

namespace Modules\Companies\Models;

use App\Models\HostPage;
use App\Models\Partner;
use App\Support\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * An Umrah company's own website — companies plan, Phase 4a.
 *
 * What the company chooses: a theme, its logo and cover, two colours
 * checked for contrast, a font the site already has, its words in each
 * language and which sections show. No page builder and no custom CSS,
 * for the reasons ADR 0008 gives host pages.
 *
 * Deliberately not {@see Concerns\BelongsToCompany}: the site is looked up
 * by its address *before* there is a company to scope to, and a scope
 * defaulting to Rihla would hide every other company's site from the very
 * lookup that finds it. Reached through {@see Partner::site()}.
 *
 * @property int $company_id
 * @property string $theme
 * @property string|null $logo_path
 * @property string|null $cover_path
 * @property string|null $colour_primary
 * @property string|null $colour_accent
 * @property string $font
 * @property list<string>|null $sections
 * @property string|null $whatsapp
 * @property string|null $instagram
 * @property string|null $facebook
 * @property Carbon|null $published_at
 */
class CompanySite extends Model
{
    use HasTranslations;

    public const CLASSIC = 'classic';

    public const MINIMAL = 'minimal';

    public const BOLD = 'bold';

    /**
     * The themes a company chooses from (Phase 4b). Each is a complete
     * layout — header, opening and the order of the home page — not a set
     * of options.
     *
     * @var array<string, string>
     */
    public const THEMES = [
        self::CLASSIC => 'Classic — a cover photograph, your trips, then your story',
        self::MINIMAL => 'Quiet — a white page, your story first, then your trips',
        self::BOLD => 'Bold — your colour across the top, your trips, then how to reach you',
    ];

    /**
     * The order each theme's home page tells it in.
     *
     * @var array<string, list<string>>
     */
    public const ORDER = [
        self::CLASSIC => ['departures', 'about', 'contact'],
        self::MINIMAL => ['about', 'departures', 'contact'],
        self::BOLD => ['departures', 'contact', 'about'],
    ];

    /** Sections a company switches on or off. Toggled, not arranged. */
    public const SECTIONS = [
        'departures' => 'Upcoming trips',
        'about' => 'About us',
        'contact' => 'How to reach us',
    ];

    /** @var list<string> */
    public array $translatable = ['tagline', 'about'];

    protected $fillable = [
        'theme', 'logo_path', 'cover_path', 'colour_primary', 'colour_accent', 'font',
        'tagline', 'about', 'sections', 'whatsapp', 'instagram', 'facebook',
    ];

    protected $casts = [
        'sections' => 'array',
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'theme' => self::CLASSIC,
        'font' => 'inter',
    ];

    /** @return BelongsTo<Partner, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'company_id');
    }

    /**
     * The home page's sections, in this theme's order, that the company has
     * switched on.
     *
     * @return list<string>
     */
    public function homeSections(): array
    {
        $order = self::ORDER[$this->theme] ?? self::ORDER[self::CLASSIC];

        return array_values(array_filter($order, fn (string $section): bool => $this->shows($section)));
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function shows(string $section): bool
    {
        return in_array($section, $this->sections ?? array_keys(self::SECTIONS), true);
    }

    /** Headings and links. */
    public function primary(): string
    {
        return self::isColour($this->colour_primary) ? (string) $this->colour_primary : Brand::INK;
    }

    /** Buttons and the header band. */
    public function accent(): string
    {
        return self::isColour($this->colour_accent) ? (string) $this->colour_accent : $this->primary();
    }

    /**
     * Only a six-digit hex colour ever reaches a page: these values are
     * printed into a stylesheet, and the form's check is not the only way
     * a row can be written.
     */
    private static function isColour(?string $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    /** White or dark words, whichever reads on the accent. */
    public function textOnAccent(): string
    {
        return HostPage::textOn($this->accent());
    }

    public function fontStack(): string
    {
        return (HostPage::FONTS[$this->font] ?? HostPage::FONTS['inter'])['stack'];
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }
}
