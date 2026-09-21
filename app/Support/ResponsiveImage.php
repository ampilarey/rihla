<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Serving an image at the size the device asked for — §10.1.
 *
 * ## What was already here, and what was not
 *
 * Hero banner uploads have been generating `_768w`, `_1280w` and `_1920w`
 * WebP files since Phase 2, and `HeroBanner::getResponsiveImageUrlsAttribute()`
 * has been returning their URLs. **No view ever called it.** Every visitor,
 * on every device, was served the full-size file — which on the homepage is
 * the Largest Contentful Paint element, so a telephone on a Maldivian mobile
 * connection downloaded a 1920-pixel photograph to show it 390 pixels wide.
 *
 * The variants existed. The `srcset` did not.
 *
 * ## Why existence is checked rather than assumed
 *
 * That accessor builds its URLs by string manipulation and never asks
 * whether the files are there. Any image uploaded before variant
 * generation existed — or one whose generation failed — yields three URLs
 * that 404. **A `srcset` of 404s is worse than no `srcset` at all**,
 * because the browser picks the one it wants and gets nothing, where
 * without it the browser would at least have loaded the original. So
 * {@see variants()} asks the disk, and a path with no variants renders as
 * a plain `<img>`.
 */
final class ResponsiveImage
{
    /**
     * The widths generated on upload.
     *
     * Matches the suffixes `HeroBannerController` has always written, so
     * images already on the server are found without regenerating
     * anything.
     */
    public const WIDTHS = [768, 1280, 1920];

    /** `hero/banner.webp` at 768 becomes `hero/banner_768w.webp`. */
    public static function variantPath(string $path, int $width): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $base = $extension === ''
            ? $path
            : Str::beforeLast($path, '.'.$extension);

        return $base.'_'.$width.'w.webp';
    }

    /**
     * The widths that actually exist on disk, largest last.
     *
     * @return array<int, string> width => public URL
     */
    public static function variants(string $path, string $disk = 'public'): array
    {
        if ($path === '') {
            return [];
        }

        $found = [];

        foreach (self::WIDTHS as $width) {
            $variant = self::variantPath($path, $width);

            if (Storage::disk($disk)->exists($variant)) {
                $found[$width] = Storage::disk($disk)->url($variant);
            }
        }

        return $found;
    }

    /**
     * The `srcset` attribute, or null when there is nothing to choose from.
     *
     * Null rather than an empty string, so a caller writing
     * `srcset="{{ ... }}"` gets no attribute rather than an empty one —
     * an empty `srcset` is not the same as an absent one to every browser.
     */
    public static function srcset(string $path, string $disk = 'public'): ?string
    {
        $variants = self::variants($path, $disk);

        if ($variants === []) {
            return null;
        }

        return collect($variants)
            ->map(fn (string $url, int $width): string => $url.' '.$width.'w')
            ->values()
            ->implode(', ');
    }

    /**
     * What `src` should be for a browser that ignores `srcset`.
     *
     * The largest variant rather than the original: the original may be
     * anything at all, including the four-megabyte photograph somebody
     * uploaded straight off a camera.
     */
    public static function fallbackUrl(string $path, string $disk = 'public'): ?string
    {
        $variants = self::variants($path, $disk);

        if ($variants === []) {
            return null;
        }

        return $variants[array_key_last($variants)];
    }
}
