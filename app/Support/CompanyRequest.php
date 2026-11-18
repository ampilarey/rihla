<?php

namespace App\Support;

use App\Http\Middleware\UseRequestedCompany;
use App\Models\Partner;
use Filament\Facades\Filament;

/**
 * Which Umrah company the current request is working for, if it is a
 * company's at all — companies plan, Phase 2b.
 *
 * Inside a company's panel, the panel's tenant. On the plain routes the
 * panel's screens open for printing and downloading (lists, invoices,
 * receipts, the books, document and slip files), the company named in the
 * link's `company` parameter, once {@see UseRequestedCompany}
 * has checked the person works there. Everywhere else, nobody's: the
 * request is Rihla's, as it always was.
 */
final class CompanyRequest
{
    /** The request attribute the middleware sets. */
    public const ATTRIBUTE = 'umrah_company';

    public static function current(): ?Partner
    {
        if (Filament::getCurrentPanel()?->getId() === 'company') {
            $tenant = Filament::getTenant();

            return $tenant instanceof Partner ? $tenant : null;
        }

        $company = request()->attributes->get(self::ATTRIBUTE);

        return $company instanceof Partner ? $company : null;
    }

    /**
     * The parameter a link from a company's screen carries to a plain route.
     *
     * @return array<string, string>
     */
    public static function parameter(): array
    {
        $company = self::current();

        return $company === null ? [] : ['company' => $company->slug];
    }

    /**
     * `route()`, carrying the company when the link is made on a company's
     * screen, so the print or download opens for that company.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = []): string
    {
        return route($name, $parameters + self::parameter());
    }
}
