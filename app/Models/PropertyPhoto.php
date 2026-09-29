<?php

namespace App\Models;

use App\Observers\CoverImageObserver;
use App\Support\ResponsiveImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * One photograph in a listing's gallery — §16.5, §16 Phase 12.3.
 *
 * `properties.cover_image` stays the cover; these are everything after it.
 * A photo belongs to the building, or to one room type when `room_type_id`
 * is set, so a room card can show its own room rather than the pool.
 *
 * ## Both halves, in one place
 *
 * The responsive variants are written when a photo is saved and removed
 * when it is replaced or deleted, and **the original goes with them**.
 * Unlike a cover ({@see CoverImageObserver}), a gallery
 * photo's file belongs to this row and nothing else: every upload mints
 * its own name, and the row is the only thing that knows it exists. A host
 * with forty photographs who replaces them each season would otherwise
 * leave hundreds of files on a disk cPanel caps — the leak AGENTS.md
 * records under "a generator with no counterpart".
 *
 * Handled in `created` and `updated` separately, because `wasChanged()` is
 * false on an insert and a single `saved` handler misses every first
 * upload (AGENTS.md).
 */
class PropertyPhoto extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = ['property_id', 'room_type_id', 'disk', 'path', 'caption', 'sort_order'];

    /** @var array<int, string> */
    public array $translatable = ['caption'];

    /** @var array<string, string> */
    protected $casts = ['sort_order' => 'integer'];

    /** @var array<string, mixed> */
    protected $attributes = ['disk' => 'public', 'sort_order' => 0];

    protected static function booted(): void
    {
        static::created(function (self $photo): void {
            ResponsiveImage::generate($photo->path, $photo->disk);
        });

        static::updated(function (self $photo): void {
            if ($photo->wasChanged(['path', 'disk'])) {
                self::remove((string) $photo->getOriginal('path'), (string) $photo->getOriginal('disk'));
                ResponsiveImage::generate($photo->path, $photo->disk);
            }
        });

        static::deleted(function (self $photo): void {
            self::remove($photo->path, $photo->disk);
        });
    }

    /** The original and every variant made from it. */
    private static function remove(string $path, string $disk): void
    {
        if ($path === '') {
            return;
        }

        ResponsiveImage::forget($path, $disk);
        Storage::disk($disk)->delete($path);
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
