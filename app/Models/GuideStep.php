<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GuideStep extends Model
{
    use HasFactory;

    /**
     * Kept deliberately in step with the table. Four of these names were
     * previously listed without existing as columns, while `description` —
     * the only body-text column there was — was missing, so every write of it
     * was silently discarded.
     */
    protected $fillable = [
        'step_number',
        'locale',
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

    protected $casts = [
        'step_number' => 'integer',
        'is_published' => 'boolean',
        'checklist' => 'array',
        'fiqh_notes' => 'array',
    ];

    /**
     * Scope for published steps
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Scope for specific locale
     */
    public function scopeForLocale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
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
     */
    public function getChecklistItemsAttribute(): array
    {
        return $this->checklist ?? [];
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
