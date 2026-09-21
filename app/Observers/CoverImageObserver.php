<?php

namespace App\Observers;

use App\Support\ResponsiveImage;
use Illuminate\Database\Eloquent\Model;

/**
 * Make an uploaded cover's responsive variants the moment it is saved — §10.1.
 *
 * Hero banner uploads have generated their own since Phase 2. Trip,
 * package and article covers never have: those go through a plain file
 * upload or a Filament `FileUpload`, both of which store exactly what
 * they were handed. So `images:responsive` backfills — and without this,
 * every cover uploaded *after* that backfill would be full-size again
 * until somebody remembered to run it, which is the state the backfill
 * existed to leave.
 *
 * Generation is synchronous, because there is no queue worker (ADR 0002).
 * Three WebP encodes cost a few hundred milliseconds on an admin save,
 * which is the right place to spend them: the alternative is spending
 * them on every visitor's connection instead, on every page view.
 *
 * It is deliberately quiet. A cover that will not encode — a PDF renamed
 * to .jpg, an image GD cannot read — leaves the record saved and the
 * original served. Failing the save would lose the editor's work over
 * something that only affects how large a download is.
 */
class CoverImageObserver
{
    /** Which column on each model holds the path. */
    public const COLUMNS = ['cover_image', 'image_path'];

    /**
     * A record that arrives with a cover already on it.
     *
     * Separate from {@see updated()} deliberately, and this is not a
     * style choice: on an insert Laravel never populates `$changes`, so
     * `wasChanged()` is false for every column. An observer that checked
     * only that would have silently missed **every first upload** — the
     * common case, and the one the whole thing is for.
     */
    public function created(Model $model): void
    {
        foreach (self::COLUMNS as $column) {
            $this->generate($model->getAttribute($column));
        }
    }

    /** A cover swapped for another one. */
    public function updated(Model $model): void
    {
        foreach (self::COLUMNS as $column) {
            // Only the save that changed it. Re-encoding three photographs
            // every time somebody edits a price would make the admin panel
            // slow for no reason at all.
            if ($model->wasChanged($column)) {
                $this->generate($model->getAttribute($column));
            }
        }
    }

    private function generate(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            ResponsiveImage::generate($path);
        }
    }
}
