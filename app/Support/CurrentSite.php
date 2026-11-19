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
        // The installable app (Phase 4d).
        'site.manifest', 'site.icon', 'site.worker', 'site.offline',
        // Checkout and the waiting list (Phase 4c). No card payment: the
        // flow ends at the seat hold and the company's own bank details.
        'booking.start', 'booking.hold', 'booking.travellers', 'booking.travellers.store',
        'booking.review', 'booking.confirm', 'booking.confirmation',
        'waitlist.join', 'waitlist.claim',
        // The customer portal and the family portal (Phase 4c), on the
        // company's address, where the company scope finds its bookings.
        'portal.enter', 'portal.locked', 'portal.login', 'portal.login.attempt',
        'portal.home', 'portal.documents', 'portal.documents.store', 'portal.payments.store',
        'portal.leave', 'portal.bookings', 'portal.bookings.choose', 'portal.password',
        'portal.family', 'portal.family.store', 'portal.family.update', 'portal.family.revoke',
        'portal.invoice', 'portal.receipt', 'portal.letters',
        'portal.agreement', 'portal.agreement.sign', 'portal.agreement.paper', 'portal.agreement.pdf',
        'family.enter', 'family.locked', 'family.home', 'family.leave',
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

        $company = Partner::query()->where('slug', $label)->first();

        return $company !== null && self::isLive($company) ? $company : null;
    }

    /**
     * Is this company's site live: an Umrah company, active, switched on by
     * Rihla's admin, with a site it has published?
     */
    public static function isLive(Partner $company): bool
    {
        return $company->is_umrah_company
            && $company->status === Partner::STATUS_ACTIVE
            && $company->site_enabled
            && $company->site?->isPublished() === true;
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
