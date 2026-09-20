<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Permitted visa types
    |--------------------------------------------------------------------------
    |
    | Which visas Rihla will apply for, and which count as valid for Umrah.
    |
    | Configuration rather than a constant, because §5.4b requires exactly
    | that: "all Saudi requirements — passport validity window, prerequisites,
    | permitted visa types, slot lead times — live in versioned configuration,
    | never as constants in code", so a mid-season policy change is a config
    | edit rather than a deployment.
    |
    | **The list is the operator's to confirm.** These are the two routes
    | Maldivian pilgrims ordinarily travel on; what Saudi Arabia accepts is
    | their rule and not this application's, and nothing here should be read
    | as advice about it.
    |
    */

    'types' => [
        'umrah' => 'Umrah visa',
        'tourist' => 'Tourist (e-visa)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Service levels
    |--------------------------------------------------------------------------
    |
    | How long an application may sit in a stage before operations should be
    | looking at it. §5.4a asks for an SLA alert when a stage stalls; this is
    | the threshold it is measured against.
    |
    | A stalled application is **computed** from these, never stored: a
    | stored "overdue" flag is wrong from the moment the clock passes it, and
    | right again only if something remembers to clear it.
    |
    | Working days are not modelled. Nobody has said which days Rihla counts,
    | and a guess that quietly shortens a deadline is worse than plain days.
    |
    */

    'sla_days' => [
        'preparing' => (int) env('VISA_SLA_PREPARING_DAYS', 3),
        'submitted' => (int) env('VISA_SLA_SUBMITTED_DAYS', 7),
    ],

];
