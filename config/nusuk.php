<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prerequisites
    |--------------------------------------------------------------------------
    |
    | What must be recorded on the departure before a permit can be requested.
    | §5.4b names accommodation and transport; both are properties of the
    | dated run rather than of a person.
    |
    | Configuration, like every other Saudi requirement in this application,
    | so that a mid-season change is a config edit rather than a deployment.
    | Turning one off is a deliberate act with a name attached, not a code
    | change nobody notices.
    |
    */

    'prerequisites' => [
        'accommodation' => (bool) env('NUSUK_REQUIRE_ACCOMMODATION', true),
        'transport' => (bool) env('NUSUK_REQUIRE_TRANSPORT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rawdah slot lead time
    |--------------------------------------------------------------------------
    |
    | How far ahead a Rawdah slot may be booked. §5.4b lists slot lead times
    | among the Saudi requirements that must live here rather than in code.
    |
    | **The number is the operator's to confirm.** It is used to warn that a
    | slot looks out of range, never to refuse one: Nusuk decides what it
    | will accept, and a client-side rule that quietly blocks a valid request
    | is worse than no rule.
    |
    */

    'rawdah_slot_lead_days' => (int) env('NUSUK_RAWDAH_LEAD_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Service levels
    |--------------------------------------------------------------------------
    |
    | How long a requested permit may sit before operations should look at
    | it. Computed on read, never stored.
    |
    */

    'sla_days' => [
        'requested' => (int) env('NUSUK_SLA_REQUESTED_DAYS', 5),
    ],

];
