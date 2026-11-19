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

    /*
    | Billing companies (the plan's "Later": invoices, reminders,
    | suspension). Amounts are never here: each company's registration fee
    | and monthly fee are set on its record in /staff. These are only the
    | clock.
    |
    | `suspend_after_days` is null — never suspend automatically — until the
    | owner decides otherwise. Set, a company with an invoice unpaid that
    | many days past its due date is suspended by the daily run, with the
    | invoice named as the reason; reactivating stays a person's decision.
    */
    'billing' => [
        'due_days' => (int) env('COMPANY_INVOICE_DUE_DAYS', 14),
        'remind_days_before' => (int) env('COMPANY_INVOICE_REMIND_DAYS', 3),
        // filled(), not `=== null`: an empty `COMPANY_SUSPEND_AFTER_DAYS=`
        // line reads as '', and (int) '' is 0 — suspend the day after due.
        'suspend_after_days' => filled(env('COMPANY_SUSPEND_AFTER_DAYS')) ? (int) env('COMPANY_SUSPEND_AFTER_DAYS') : null,
    ],

];
