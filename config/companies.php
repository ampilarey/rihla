<?php

/*
|--------------------------------------------------------------------------
| Umrah companies on Rihla — docs/UMRAH_COMPANIES_PLAN.md
|--------------------------------------------------------------------------
*/

return [

    /*
    | The domain whose first-level names are company websites (Phase 4a):
    | `coral.rihla.mv` serves Coral's site. Null — and every company site
    | unreachable — until the owner adds the wildcard subdomain `*.rihla.mv`
    | in cPanel, pointing at the app's document root, and sets this to the
    | bare domain. Names in `marketplace.host_subdomains.reserved` are never
    | read as a company: guesthouse hosts and companies share one name
    | space.
    */
    'site_domain' => env('COMPANY_SITE_DOMAIN'),

];
