<?php

namespace App\Models;

use App\Support\GuideStepImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class GuideStep extends Model
{
    use HasFactory, HasTranslations;

    /**
     * Kept deliberately in step with the table. Four of these names were
     * previously listed without existing as columns, while `description` —
     * the only body-text column there was — was missing, so every write of it
     * was silently discarded.
     */
    protected $fillable = [
        'step_number',
        'title',
        'summary',
        'details',
        'dua_text',
        'reference_text',
        'fiqh_notes',
        'video_url',
        'image_path',
        'checklist',
        'is_published',
    ];

    /**
     * Stored as `{"en": …, "dv": …}` and read back in the request's locale,
     * falling back to English. One step is one row in both languages; it used
     * to be one row per language, joined to its twin by nothing but a shared
     * `step_number`.
     *
     * `dua_text` is deliberately absent. It is the Arabic of the rite — the
     * same words whatever language the page is in, so it is data rather than
     * a translation. See docs/adr/0001-how-content-is-translated.md.
     *
     * @var array<int, string>
     */
    public array $translatable = ['title', 'summary', 'details', 'reference_text', 'fiqh_notes', 'checklist'];

    protected $casts = [
        'step_number' => 'integer',
        'is_published' => 'boolean',
        'checklist' => 'array',
        'fiqh_notes' => 'array',
    ];

    /**
     * A replaced or deleted picture leaves the disk with it.
     *
     * The Blade controller did this by hand in update() and destroy(), so a
     * step changed by any other route kept its old files for ever. On the
     * model, the staff panel and anything after it get it for free.
     * `updated` rather than `saved`: `wasChanged()` is false on an insert,
     * and an insert has nothing to replace anyway.
     */
    protected static function booted(): void
    {
        static::updated(static function (GuideStep $step): void {
            if ($step->wasChanged('image_path')) {
                GuideStepImage::forget($step->getOriginal('image_path'));
            }
        });

        static::deleted(static function (GuideStep $step): void {
            GuideStepImage::forget($step->image_path);
        });
    }

    /**
     * Scope for published steps
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Scope for ordered steps
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('step_number');
    }

    /**
     * Get the full image URL
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }

    /**
     * Get checklist items as array
     *
     * A translated attribute with nothing stored for this locale comes back
     * as an empty *string*, not null and not an empty array — so `?? []` let
     * a string reach code that foreach'd over it.
     */
    public function getChecklistItemsAttribute(): array
    {
        return is_array($this->checklist) ? $this->checklist : [];
    }

    /**
     * Fiqh notes as an array, whatever the column holds.
     *
     * Named `fiqh_notes_list` rather than `fiqh_notes` on purpose — see below.
     */
    public function getFiqhNotesListAttribute(): array
    {
        return is_array($this->fiqh_notes) ? $this->fiqh_notes : [];
    }

    // There is deliberately no getFiqhNotesAttribute() accessor. The one that
    // used to be here read `$this->fiqh_notes` — its own attribute — and so
    // shadowed the `array` cast and always returned []. Fiqh notes were saved
    // to the database and could never be read back, which is why they never
    // appeared on the guide. The cast alone does the right thing.

    /**
     * Check if step has checklist
     */
    public function hasChecklist(): bool
    {
        return ! empty($this->checklist);
    }

    /**
     * Check if step has Fiqh notes
     */
    public function hasFiqhNotes(): bool
    {
        return ! empty($this->fiqh_notes);
    }

    /**
     * Check if step has dua
     */
    public function hasDua(): bool
    {
        return ! empty($this->dua_text);
    }

    /**
     * Check if step has details
     */
    public function hasDetails(): bool
    {
        return ! empty($this->details);
    }

    /**
     * Check if step has video
     */
    public function hasVideo(): bool
    {
        return ! empty($this->video_url);
    }

    /**
     * Check if step has image
     */
    public function hasImage(): bool
    {
        return ! empty($this->image_path);
    }

    /**
     * Get thumbnail image URL
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $pathInfo = pathinfo($this->image_path);
        $thumbnailPath = $pathInfo['dirname'].'/thumb_'.$pathInfo['basename'];

        return asset('storage/'.$thumbnailPath);
    }

    /**
     * Get large image URL
     */
    public function getLargeImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $pathInfo = pathinfo($this->image_path);
        $largePath = $pathInfo['dirname'].'/large_'.$pathInfo['basename'];

        return asset('storage/'.$largePath);
    }
}
