<?php

namespace App\Support;

use App\Models\Partner;
use DateTimeInterface;
use Illuminate\Support\Facades\URL;

/**
 * Where a link for one company's customer points — companies plan, Phase 4c.
 *
 * A portal link, a waiting-list claim or anything else a customer opens is
 * only any use on the site that can find their booking: Rihla's on rihla.mv,
 * another company's on its own address, where the company scope reaches it.
 * A link made in the company's panel (on rihla.mv) would otherwise point at
 * rihla.mv, which cannot open it and would show the customer Rihla's name.
 *
 * Null when a company has no live site yet: there is nowhere such a link
 * could work, and the screens say so rather than hand out a dead one.
 */
final class SiteAddress
{
    /** rihla.mv's own root for Rihla, the company's live site for anybody else, or null. */
    public static function root(?int $companyId): ?string
    {
        if (self::isRihla($companyId)) {
            return rtrim((string) config('app.url'), '/');
        }

        $company = Partner::find($companyId);
        $domain = config('companies.site_domain');

        if ($company === null || blank($domain) || ! CurrentSite::isLive($company)) {
            return null;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return $scheme.'://'.$company->slug.'.'.$domain;
    }

    /**
     * A route on the company's site. Rihla's links are made exactly as
     * before.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters, ?int $companyId): ?string
    {
        if (self::isRihla($companyId)) {
            return route($name, $parameters);
        }

        $root = self::root($companyId);

        return $root === null ? null : $root.route($name, $parameters, false);
    }

    /**
     * A signed, expiring route on the company's site. The signature covers
     * the whole address, the company's host included, so it is made with
     * that host as the root and checked on it.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function temporarySignedRoute(string $name, DateTimeInterface $expires, array $parameters, ?int $companyId): ?string
    {
        if (self::isRihla($companyId)) {
            return URL::temporarySignedRoute($name, $expires, $parameters);
        }

        $root = self::root($companyId);

        if ($root === null) {
            return null;
        }

        URL::forceRootUrl($root);

        try {
            return URL::temporarySignedRoute($name, $expires, $parameters);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private static function isRihla(?int $companyId): bool
    {
        return $companyId === null || $companyId === app(CompanyContext::class)->rihlaId();
    }
}
