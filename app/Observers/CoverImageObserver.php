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
 *
 * ## It deletes as well as generates, which it did not
 *
 * Variants were written on every save of four models and removed by
 * exactly one place in the codebase — `HeroBannerController::deleteImage()`
 * — so a trip, package or article cover that was replaced or deleted left
 * its three WebP files on disk, and had since Phase 2. Nothing reported
 * it: the site is correct either way, and the only symptom is a disk
 * filling up. On cPanel that allowance is fixed, and the failure when it
 * runs out is writes failing across the whole application.
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
                // The one it replaced, first. Generation has existed since
                // Phase 2 and deletion never has, so every cover ever
                // swapped left three WebP files behind it.
                $this->forget($model->getOriginal($column));
                $this->generate($model->getAttribute($column));
            }
        }
    }

    /**
     * The record is gone, so its derived files are not about anything.
     *
     * Only the variants. Whether the **original** should go is a separate
     * decision this observer cannot make — a soft-deleted record still
     * points at its file, and a path shared by two records would take the
     * other one's image with it. Every upload path in this application
     * mints a unique filename, so the variants are safe; the original is
     * left for whoever owns that decision.
     */
    public function deleted(Model $model): void
    {
        foreach (self::COLUMNS as $column) {
            $this->forget($model->getAttribute($column));
        }
    }

    private function forget(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            ResponsiveImage::forget($path);
        }
    }

    private function generate(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            ResponsiveImage::generate($path);
        }
    }
}
