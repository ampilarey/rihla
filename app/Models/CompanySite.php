<?php

namespace App\Models;

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

    /** @var array<string, string> */
    public const THEMES = [
        self::CLASSIC => 'Classic — a cover photograph, your trips, then your story',
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
        return $this->colour_primary ?: Brand::INK;
    }

    /** Buttons and the header band. */
    public function accent(): string
    {
        return $this->colour_accent ?: $this->primary();
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
