<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `{slug}.rihla.mv` — §16 Phase 16.
 *
 * Sends the visitor to the host's page on the main domain, in the language
 * their browser asks for. A temporary redirect, not a permanent one: a
 * browser keeps a 301 for ever, and a page that is later unpublished must
 * stop being reachable, not stay cached as a promise.
 *
 * Only a host whose page is live is answered; any other name is a 404, so
 * the subdomain says nothing about which hosts exist.
 */
class HostSubdomainController extends Controller
{
    public function __invoke(Request $request, string $hostSlug): RedirectResponse
    {
        // The route pattern keeps these out; this is the second lock.
        abort_if(in_array(strtolower($hostSlug), (array) config('marketplace.host_subdomains.reserved', []), true), 404);

        $host = Partner::query()
            ->where('slug', strtolower($hostSlug))
            ->where('status', Partner::STATUS_ACTIVE)
            ->where('verification', Partner::VERIFIED)
            ->whereHas('page', fn ($page) => $page->whereNotNull('published_at'))
            ->first();

        abort_if($host === null, 404);

        $locale = $request->getPreferredLanguage(SetLocale::SUPPORTED) ?? 'en';
        $domain = (string) config('marketplace.host_subdomains.domain');

        return redirect()->away(
            $request->getScheme().'://'.$domain.'/'.$locale.'/stays/hosts/'.$host->slug,
            302,
        );
    }

    /** The pattern a subdomain must match to be read as a host at all. */
    public static function pattern(): string
    {
        $reserved = implode('|', array_map('preg_quote', (array) config('marketplace.host_subdomains.reserved', [])));

        // Symfony matches the whole host, `coral.rihla.mv`, so the name ends
        // at the dot that follows it, not at the end of the string: a `$`
        // here would never match and every reserved name would slip through.
        return '(?!(?:'.$reserved.')\\.)[a-z0-9](?:[a-z0-9-]{0,62})';
    }
}
