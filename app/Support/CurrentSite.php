<?php

namespace App\Support;

use App\Models\CompanySite;
use App\Models\Partner;

/**
 * Whose website this request is — companies plan, Phase 4a.
 *
 * Null company means rihla.mv, exactly as before. A company means the
 * request arrived on that company's own address and every page answers as
 * its site: {@see CompanyContext} follows it, so every Umrah query sees that
 * company's records only, and `layouts.app` hands the page to its theme.
 *
 * Bound as a scoped instance, for the reason CompanyContext is.
 */
final class CurrentSite
{
    /**
     * The route names a company's site answers. Every other route is a 404
     * on a company's address, so a page Rihla adds later is absent from
     * company sites by default rather than present by mistake: the
     * articles, the guide, trips, stays and the rest are Rihla's own content
     * and carry no company at all.
     *
     * @var list<string>
     */
    public const ROUTES = [
        'home',
        'packages.index',
        'packages.compare',
        'packages.show',
        'contact',
        'enquiries.store',
        'sitemap',
        'robots',
    ];

    private ?Partner $company = null;

    private ?CompanySite $site = null;

    /**
     * The company whose live site a host name is, or null for any other
     * host. Live means: an Umrah company, active, switched on by Rihla's
     * admin, with a site it has published.
     */
    public static function companyFor(string $host): ?Partner
    {
        $label = self::label($host);

        if ($label === null) {
            return null;
        }

        return Partner::query()
            ->where('slug', $label)
            ->where('is_umrah_company', true)
            ->where('status', Partner::STATUS_ACTIVE)
            ->where('site_enabled', true)
            ->whereHas('site', fn ($site) => $site->whereNotNull('published_at'))
            ->first();
    }

    /**
     * The company name a host carries — `coral` for `coral.rihla.mv` — or
     * null when the host is not a first-level name of the companies domain,
     * or is one of the reserved names (`www`, `test`, `mail`, `cpanel`…).
     */
    public static function label(string $host): ?string
    {
        $domain = strtolower((string) config('companies.site_domain'));
        $host = strtolower($host);

        if ($domain === '' || ! str_ends_with($host, '.'.$domain)) {
            return null;
        }

        $label = substr($host, 0, -strlen('.'.$domain));

        // One level only: the free certificate covers `coral.rihla.mv`,
        // not `a.coral.rihla.mv`.
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        if (in_array($label, (array) config('marketplace.host_subdomains.reserved', []), true)) {
            return null;
        }

        return $label;
    }

    public function use(Partner $company): void
    {
        $this->company = $company;
        $this->site = $company->site;
    }

    /**
     * Stop being a company's site. True when it was one. Each request on a
     * live server is a fresh process, so this only matters where one
     * application serves several requests in turn (a test, a queue worker).
     */
    public function forget(): bool
    {
        $was = $this->company !== null;
        $this->company = null;
        $this->site = null;

        return $was;
    }

    public function company(): ?Partner
    {
        return $this->company;
    }

    public function site(): ?CompanySite
    {
        return $this->site;
    }

    /** Is this request a company's site rather than rihla.mv? */
    public static function active(): bool
    {
        return app(self::class)->company !== null;
    }

    /** May a link to this route be shown here? Always on rihla.mv. */
    public static function allows(string $route): bool
    {
        return ! self::active() || in_array($route, self::ROUTES, true);
    }
}
