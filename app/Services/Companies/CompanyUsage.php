<?php

namespace App\Services\Companies;

use App\Models\Booking;
use App\Models\CompanyMembership;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\Package;
use App\Models\Partner;
use App\Models\Traveller;
use App\Support\CompanyContext;

/**
 * How much a company uses Rihla, as counts and never contents — companies
 * plan, Phase 5b. What Rihla's admin needs to price and support a company,
 * and nothing that would let anybody at Rihla read its customers.
 */
final class CompanyUsage
{
    /** @return array<string, int> label => count */
    public function counts(Partner $company): array
    {
        $counts = app(CompanyContext::class)->as((int) $company->getKey(), fn (): array => [
            'Packages' => Package::query()->count(),
            'Departures' => Departure::query()->count(),
            'Bookings' => Booking::query()->count(),
            'Bookings this year' => Booking::query()->where('created_at', '>=', now()->startOfYear())->count(),
            'Customers' => Customer::query()->count(),
            'Travellers' => Traveller::query()->count(),
            'Documents' => Document::query()->count(),
        ]);

        return $counts + ['Staff' => CompanyMembership::where('company_id', $company->getKey())->count()];
    }
}
