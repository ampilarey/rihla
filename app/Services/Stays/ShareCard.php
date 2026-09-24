<?php

namespace App\Services\Stays;

use App\Models\Property;
use Illuminate\Support\Facades\Storage;

/**
 * The 1200×630 preview a pasted link shows — §15.4 (Phase 9.5).
 *
 * This is the owner's stated need, in its most literal form: *"so I can
 * share information for them easily"*. A link dropped into WhatsApp either
 * unfurls into a picture of the guesthouse with its name under it, or it
 * unfurls into the Rihla logo and says nothing about the property. The
 * difference is this class.
 *
 * ## Why the cover is cropped rather than linked
 *
 * A guesthouse photograph is whatever shape the owner's phone took it in.
 * Handed to a scraper at 3:4, WhatsApp and Facebook each crop it their own
 * way — usually through the middle of the building — and Twitter letterboxes
 * it. 1200×630 is the one ratio every one of them renders whole. Cropping
 * centrally here is a decision made once, visibly, instead of three
 * different decisions made badly by other people's software.
 *
 * ## The URL carries a content hash, and that is not decoration
 *
 * Every scraper caches by URL, often for weeks, and none of them re-check
 * on a schedule anybody can rely on. A stable `/card.png` whose bytes
 * change is the same defect `AGENTS.md` records for the service worker:
 * *a path that outlives its contents serves last year's artwork forever*.
 * So the URL carries a hash of the source image, and replacing a cover
 * produces a URL nothing has seen before.
 *
 * ## It degrades rather than failing
 *
 * GD is near-universal on cPanel but not promised, and a property may have
 * no cover at all. Either way this returns null and the page falls back to
 * the brand image — a plain preview is a smaller problem than a 500 on a
 * page somebody is trying to share.
 */
class ShareCard
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    private const DISK = 'public';

    private const DIRECTORY = 'share-cards';

    /**
     * The card's bytes, generating and caching them if need be.
     *
     * Null when there is nothing to make one from, or no GD to make it
     * with. The caller falls back to the brand image.
     */
    public function bytes(Property $property): ?string
    {
        if (! $this->isSupported() || ! $this->hasCover($property)) {
            return null;
        }

        $cached = $this->path($property);

        if ($cached !== null && Storage::disk(self::DISK)->exists($cached)) {
            return Storage::disk(self::DISK)->get($cached);
        }

        $png = $this->render($property);

        if ($png === null) {
            return null;
        }

        if ($cached !== null) {
            Storage::disk(self::DISK)->put($cached, $png);
        }

        return $png;
    }

    /**
     * A fingerprint of the source image, for the URL.
     *
     * Taken from the stored path *and* its size, so replacing a cover with
     * a different photograph under the same filename — which is what an
     * "upload a new picture" button does on some setups — still produces a
     * new URL. Null when there is no cover to fingerprint.
     */
    public function version(Property $property): ?string
    {
        if (! $this->hasCover($property)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $cover = (string) $property->cover_image;

        return substr(hash('xxh128', $cover.'|'.$disk->size($cover)), 0, 12);
    }

    public function isSupported(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function hasCover(Property $property): bool
    {
        return filled($property->cover_image)
            && Storage::disk(self::DISK)->exists((string) $property->cover_image);
    }

    private function path(Property $property): ?string
    {
        $version = $this->version($property);

        return $version === null
            ? null
            : self::DIRECTORY.'/'.$property->slug.'-'.$version.'.png';
    }

    /**
     * Centre-crop to 1200×630 and re-encode.
     *
     * `imagecreatefromstring` rather than the per-format constructors: it
     * reads whatever the upload actually was, which is not always what its
     * extension claimed.
     */
    private function render(Property $property): ?string
    {
        $source = @imagecreatefromstring(
            Storage::disk(self::DISK)->get((string) $property->cover_image) ?? '',
        );

        if ($source === false) {
            return null;
        }

        // Both are int<1, max>: GD cannot hand back a zero-sized image for
        // a resource it accepted, so there is nothing to guard against here.
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        // Cover, not contain: fill the frame and lose the overflow, rather
        // than pad it. Bars around a guesthouse photograph look like a
        // broken image; a tighter crop looks like a photograph.
        $scale = max(self::WIDTH / $sourceWidth, self::HEIGHT / $sourceHeight);
        $scaledWidth = (int) ceil($sourceWidth * $scale);
        $scaledHeight = (int) ceil($sourceHeight * $scale);

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        imagecopyresampled(
            $canvas,
            $source,
            (int) ((self::WIDTH - $scaledWidth) / 2),
            (int) ((self::HEIGHT - $scaledHeight) / 2),
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        imagepng($canvas, null, 6);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        return $png === '' ? null : $png;
    }
}
