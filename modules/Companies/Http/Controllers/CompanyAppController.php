<?php

namespace Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Support\CurrentSite;
use GdImage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Modules\Companies\Models\CompanySite;

/**
 * Each company's own installable app — companies plan, Phase 4d.
 *
 * A pilgrim who adds a company's site to their home screen installs that
 * company, not Rihla: its name, its icon (drawn from its logo, or its
 * initials on its colour when it has none), its colour in the status bar,
 * and an offline page in its name. The service worker is served from the
 * company's own address, so its caches belong to that origin and two
 * companies' apps on one phone never share one.
 *
 * Only on a company's address: rihla.mv keeps its own manifest and worker
 * in public/, which these routes never reach.
 */
class CompanyAppController extends Controller
{
    /** The icon sizes the manifest names, square, in pixels. */
    public const SIZES = [192, 512];

    public function manifest(): JsonResponse
    {
        [$company, $site] = $this->current();
        $tagline = $site->getTranslation('tagline', 'en');

        return response()->json(array_filter([
            'name' => $company->name,
            'short_name' => mb_strimwidth($company->name, 0, 12, ''),
            'description' => $tagline ?: null,
            'id' => '/',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#FFFFFF',
            'theme_color' => $site->accent(),
            'icons' => array_merge(...array_map(fn (int $size): array => [
                ['src' => route('site.icon', ['size' => $size], false), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('site.icon', ['size' => $size], false), 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'maskable'],
            ], self::SIZES)),
            'prefer_related_applications' => false,
        ]), 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * The company's icon at one size, made once and kept until its logo,
     * colour or name changes (the file name carries all three).
     */
    public function icon(int $size): Response
    {
        abort_unless(in_array($size, self::SIZES, true), 404);
        [$company, $site] = $this->current();

        $key = substr(sha1(implode('|', [$site->logo_path, $site->accent(), $company->name])), 0, 12);
        $path = 'company-icons/'.$company->getKey().'-'.$size.'-'.$key.'.png';
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            $disk->put($path, $this->draw($company, $site, $size));
        }

        return response((string) $disk->get($path), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * The service worker: the company's offline page kept, and every page
     * fetched from the network first so nothing a company changes is ever
     * served stale. Its cache is named after the company and lives on the
     * company's own origin.
     */
    public function worker(): Response
    {
        [$company] = $this->current();

        return response(view('sites.worker', ['cache' => 'site-'.$company->slug.'-v1'])->render(), 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /** What a phone shows when there is no signal: the company's name, nothing else's. */
    public function offline(): View
    {
        [$company, $site] = $this->current();

        return view('sites.offline', ['company' => $company, 'site' => $site]);
    }

    /** @return array{Partner, CompanySite} */
    private function current(): array
    {
        $current = app(CurrentSite::class);
        abort_if($current->company() === null || $current->site() === null, 404);

        return [$current->company(), $current->site()];
    }

    private function draw(Partner $company, CompanySite $site, int $size): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = sscanf($site->accent(), '#%02x%02x%02x');
        $logo = $this->logo($site);

        if ($logo !== null) {
            // The logo on white, with room around it: a maskable icon is cut
            // to a circle on some phones, and the safe zone is the middle 80%.
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            $box = (int) round($size * 0.7);
            $w = imagesx($logo);
            $h = imagesy($logo);
            $scale = min($box / $w, $box / $h);
            $dw = max(1, (int) round($w * $scale));
            $dh = max(1, (int) round($h * $scale));
            imagecopyresampled($canvas, $logo, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
        } else {
            // The company's initials on its colour, in whichever of white and
            // dark reads on it.
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b));
            [$tr, $tg, $tb] = sscanf($site->textOnAccent(), '#%02x%02x%02x');
            $ink = (int) imagecolorallocate($canvas, (int) $tr, (int) $tg, (int) $tb);
            $initials = $this->initials($company->name);
            $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');

            if (function_exists('imagettftext') && is_file($font)) {
                $points = $size * 0.36;
                $bounds = (array) imagettfbbox($points, 0, $font, $initials);
                $tw = $bounds[2] - $bounds[0];
                $th = $bounds[1] - $bounds[7];
                imagettftext($canvas, $points, 0, (int) (($size - $tw) / 2 - $bounds[0]), (int) (($size + $th) / 2 - $bounds[1]), $ink, $font, $initials);
            } else {
                imagestring($canvas, 5, (int) ($size / 2 - 9), (int) ($size / 2 - 8), $initials, $ink);
            }
        }

        ob_start();
        imagepng($canvas);

        return (string) ob_get_clean();
    }

    private function logo(CompanySite $site): ?GdImage
    {
        if (blank($site->logo_path) || ! Storage::disk('public')->exists($site->logo_path)) {
            return null;
        }

        $image = @imagecreatefromstring((string) Storage::disk('public')->get($site->logo_path));

        return $image instanceof GdImage ? $image : null;
    }

    private function initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
        $letters = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), array_slice($words, 0, 2));

        return implode('', $letters) ?: '?';
    }
}
