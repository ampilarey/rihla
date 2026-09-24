<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A gallery photograph: a 1600px WebP, a 400px thumbnail, and the original.
 *
 * Moved out of the Blade admin's controller when the screen moved to /staff
 * (§9.2), where it was written out twice, once in store() and once in
 * update(). The three files share one generated name, so each can be found
 * from the path the record stores.
 *
 * ## The original is now findable
 *
 * The controller kept the upload as `$file->store('media/original')` —
 * under a random name nothing recorded. It could never be matched to its
 * item, so it could never be deleted, and every photograph ever replaced or
 * removed left its full-size original on a disk cPanel caps. It is kept
 * (it is the only full-resolution copy) but under the same name as the
 * other two, so {@see forget()} can remove it with them.
 */
final class MediaImage
{
    private const LARGE = 'media/large';

    private const THUMBS = 'media/thumbs';

    private const ORIGINALS = 'media/original';

    /** Extensions an original can have — the ones the form accepts. */
    private const ORIGINAL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @return string the large image's path, which is what `file_path` holds */
    public static function store(UploadedFile $file): string
    {
        // The client's own filename is not used: it is attacker-supplied,
        // and time() alone collides for two uploads in the same second.
        $name = now()->format('Ymd_His').'_'.Str::random(8);

        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());

        if (in_array($extension, self::ORIGINAL_EXTENSIONS, true)) {
            $file->storeAs(self::ORIGINALS, $name.'.'.$extension, 'public');
        }

        Image::fromUpload($file)
            ->scale(width: 1600)
            ->toWebp()
            ->quality(80)
            ->storeAs(self::LARGE, $name.'.webp', 'public');

        Image::fromUpload($file)
            ->scale(width: 400)
            ->toWebp()
            ->quality(80)
            ->storeAs(self::THUMBS, $name.'.webp', 'public');

        return self::LARGE.'/'.$name.'.webp';
    }

    /** The thumbnail written beside a large image, or null for anything else. */
    public static function thumbFor(?string $path): ?string
    {
        if ($path === null || ! str_starts_with($path, self::LARGE.'/')) {
            return null;
        }

        return self::THUMBS.'/'.basename($path);
    }

    /** The large image, its thumbnail and its original. */
    public static function forget(?string $path, ?string $thumb = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);

        $files = [$path, $thumb ?? self::thumbFor($path)];

        if (str_starts_with($path, self::LARGE.'/')) {
            foreach (self::ORIGINAL_EXTENSIONS as $extension) {
                $files[] = self::ORIGINALS.'/'.$name.'.'.$extension;
            }
        }

        Storage::disk('public')->delete(array_values(array_filter($files)));
    }
}
