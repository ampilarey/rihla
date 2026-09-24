<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A guide step's photograph: resized, re-encoded to WebP, with a thumbnail.
 *
 * Moved out of the Blade admin's controller when the screen moved to /staff
 * (§9.2), so the staff panel stores exactly what the old form stored. A
 * Filament `FileUpload` left to itself keeps the upload as it arrived — a
 * 6 MB phone JPEG served whole on the guide, which pilgrims open on a
 * roaming connection.
 */
final class GuideStepImage
{
    public const DIRECTORY = 'guide';

    /**
     * Uses the framework's own image facade rather than Intervention's. Both
     * bind the container key `image`, and on Laravel 13 the framework's
     * binding wins — so `Image::make()` from Intervention v2 resolved
     * Laravel's driver and died on a v3-only method.
     */
    public static function store(UploadedFile $image): string
    {
        // A random component, not just time(): 'step_'.time() collides for
        // any two images uploaded in the same second, and the second silently
        // overwrote the first, leaving one step showing another's picture.
        $name = 'step_'.now()->format('Ymd_His').'_'.Str::random(8);

        Image::fromUpload($image)
            ->scale(width: 1200)
            ->toWebp()
            ->quality(85)
            ->storeAs(self::DIRECTORY, $name.'.webp', 'public');

        Image::fromUpload($image)
            ->scale(width: 400)
            ->toWebp()
            ->quality(85)
            ->storeAs(self::DIRECTORY, $name.'-thumb.webp', 'public');

        return self::DIRECTORY.'/'.$name.'.webp';
    }

    /** The picture and the thumbnail written beside it. */
    public static function forget(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $disk = Storage::disk('public');

        $disk->delete([
            $path,
            pathinfo($path, PATHINFO_DIRNAME).'/'.pathinfo($path, PATHINFO_FILENAME).'-thumb.webp',
        ]);
    }
}
