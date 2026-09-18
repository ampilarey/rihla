<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Trip;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Every public URL, in every language.
     *
     * Each entry carries xhtml:link alternates for all locales, which is how a
     * crawler learns that /en/trips and /dv/trips are one page in two
     * languages rather than duplicate content competing with each other.
     */
    public function index(): Response
    {
        $xml = Cache::remember('sitemap.xml', now()->addHour(), fn () => $this->build());

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    private function build(): string
    {
        $trips = Trip::published()
            ->orderByDesc('date_start')
            ->get(['slug', 'updated_at']);

        $entries = [];

        // Static pages. Weekly rather than daily: claiming a change frequency
        // the site does not honour teaches the crawler to ignore the hint.
        foreach ([
            ['path' => '', 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['path' => '/trips', 'changefreq' => 'weekly', 'priority' => '0.9'],
            ['path' => '/guide', 'changefreq' => 'monthly', 'priority' => '0.8'],
            ['path' => '/gallery', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['path' => '/contact', 'changefreq' => 'yearly', 'priority' => '0.5'],
            ['path' => '/social', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ] as $page) {
            $entries[] = [
                'path' => $page['path'],
                'lastmod' => null,
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
            ];
        }

        foreach ($trips as $trip) {
            $entries[] = [
                'path' => '/trips/'.$trip->slug,
                'lastmod' => $trip->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            .'xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($entries as $entry) {
            foreach (SetLocale::SUPPORTED as $locale) {
                $xml .= "  <url>\n";
                $xml .= '    <loc>'.e(url('/'.$locale.$entry['path']))."</loc>\n";

                foreach (SetLocale::SUPPORTED as $alternate) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="'.$alternate
                        .'" href="'.e(url('/'.$alternate.$entry['path']))."\"/>\n";
                }

                $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                    .e(url('/en'.$entry['path']))."\"/>\n";

                if ($entry['lastmod'] !== null) {
                    $xml .= '    <lastmod>'.$entry['lastmod']."</lastmod>\n";
                }

                $xml .= '    <changefreq>'.$entry['changefreq']."</changefreq>\n";
                $xml .= '    <priority>'.$entry['priority']."</priority>\n";
                $xml .= "  </url>\n";
            }
        }

        return $xml.'</urlset>'."\n";
    }
}
