<?php

/*
|--------------------------------------------------------------------------
| The Stays marketplace — §16 of docs/WEBSITE_UPGRADE_PLAN.md
|--------------------------------------------------------------------------
|
| Every value here is the owner's to state. Where a value is null, the
| code that needs it refuses rather than guesses — a marketplace booking
| with no commission recorded is a silent loss, and an invented rate is
| the same mistake as the invented social links (AGENTS.md).
|
*/

return [

    /*
    | What a local pays in. A tourist pays in the property's own currency,
    | which is what every room was priced in before §16 and stays so: a
    | guesthouse quotes foreigners in dollars and a Malé room may be in
    | rufiyaa, and neither is converted (§16.2 rule 5).
    */
    'currencies' => [
        'local' => env('MARKETPLACE_LOCAL_CURRENCY', 'MVR'),
        // Only what a tourist's price *range* in search is typed in. A
        // tourist is still charged in each property's own currency.
        'tourist' => env('MARKETPLACE_TOURIST_CURRENCY', 'USD'),
    ],

    /*
    | Rihla's commission on a marketplace booking, in whole percent, when a
    | host has none of their own. Null until the owner states it (§16.16).
    */
    'default_commission_pct' => env('MARKETPLACE_COMMISSION_PCT') === null
        ? null
        : (int) env('MARKETPLACE_COMMISSION_PCT'),

    /*
    | Only hosts whose Ministry of Tourism registration somebody at Rihla has
    | checked may go live (§16.3 decision 3).
    */
    'require_registration' => (bool) env('MARKETPLACE_REQUIRE_REGISTRATION', true),

    /*
    | Hosts signing themselves up at /host/register — §16.6, §16.12. Off
    | until the owner opens it, which waits on a media disk for their
    | photographs, mail for their invitations, and host terms to agree to.
    | The page stays closed while either terms value is empty: a checkbox
    | agreeing to a document that does not exist records nothing.
    */
    'host_registration' => [
        'enabled' => (bool) env('HOST_REGISTRATION_OPEN', false),
        'per_hour' => (int) env('HOST_REGISTRATION_PER_HOUR', 5),
    ],

    'host_terms' => [
        'version' => env('HOST_TERMS_VERSION'),
        'url' => env('HOST_TERMS_URL'),
    ],

    /*
    | Host subdomains — §16 Phase 16. `coral.rihla.mv` opens Coral's page.
    | Off (null) until the owner adds a wildcard DNS record (`*.rihla.mv`)
    | and a certificate that covers it; set the bare domain here then.
    | The names below are never read as a host: they are the site's own, or
    | the hosting panel's, and a host who took one would shadow it.
    */
    'host_subdomains' => [
        'domain' => env('HOST_SUBDOMAIN_DOMAIN'),
        'reserved' => [
            'www', 'test', 'staging', 'stage', 'dev', 'demo', 'app', 'api', 'admin', 'staff', 'host', 'hosts',
            'mail', 'webmail', 'smtp', 'imap', 'pop', 'ftp', 'cpanel', 'whm', 'webdisk', 'cpcalendars', 'cpcontacts',
            'autodiscover', 'autoconfig', 'ns1', 'ns2', 'cdn', 'static', 'assets', 'status',
        ],
    ],

];
