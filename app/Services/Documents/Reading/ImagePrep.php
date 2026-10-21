<?php

namespace App\Services\Documents\Reading;

use GdImage;
use RuntimeException;

/**
 * The little image work a reader needs, with GD because that is what the
 * host has: decode, scale, grey, crop, and a look at the border for the
 * photo check. Never touches the stored original.
 */
final class ImagePrep
{
    public static function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('Not an image GD can read.');
        }

        return $image;
    }

    /** @return array{int, int} */
    public static function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw new RuntimeException('Not an image.');
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /** A grey PNG no wider than $maxWidth, which is what OCR reads best. */
    public static function forOcr(string $bytes, int $maxWidth = 2200): string
    {
        $image = self::decode($bytes);
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > $maxWidth) {
            $scaled = imagescale($image, $maxWidth, (int) round($height * $maxWidth / $width), IMG_BICUBIC);

            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_CONTRAST, -15);

        return self::png($image);
    }

    /** The bottom part of the image — where a passport keeps its machine-readable zone. */
    public static function bottom(string $bytes, float $fraction = 0.35): string
    {
        $image = self::decode($bytes);
        $width = imagesx($image);
        $height = imagesy($image);
        $top = (int) round($height * (1 - $fraction));

        $crop = imagecrop($image, ['x' => 0, 'y' => $top, 'width' => $width, 'height' => $height - $top]);
        imagedestroy($image);

        if ($crop === false) {
            throw new RuntimeException('Could not crop the image.');
        }

        return self::png($crop);
    }

    /**
     * How light and how even the outer border is: a passport photo on a
     * plain background has a bright border with little variation.
     *
     * @return array{mean: float, stddev: float}
     */
    public static function borderStats(string $bytes): array
    {
        $image = self::decode($bytes);
        $width = imagesx($image);
        $height = imagesy($image);
        $band = max(2, (int) round(min($width, $height) * 0.06));
        $values = [];
        $step = max(1, (int) floor(max($width, $height) / 120));

        for ($x = 0; $x < $width; $x += $step) {
            for ($y = 0; $y < $band; $y++) {
                $values[] = self::luma($image, $x, $y);
                $values[] = self::luma($image, $x, $height - 1 - $y);
            }
        }

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $band; $x++) {
                $values[] = self::luma($image, $x, $y);
                $values[] = self::luma($image, $width - 1 - $x, $y);
            }
        }

        imagedestroy($image);

        $count = max(1, count($values));
        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn (float $v): float => ($v - $mean) ** 2, $values)) / $count;

        return ['mean' => $mean, 'stddev' => sqrt($variance)];
    }

    private static function luma(GdImage $image, int $x, int $y): float
    {
        $rgb = imagecolorat($image, $x, $y);

        return 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
    }

    private static function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
