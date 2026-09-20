<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Access links
    |--------------------------------------------------------------------------
    |
    | How long a portal link stays good, and how long a session lasts once
    | somebody has used one.
    |
    | Long by the standards of a download link, because this is the customer's
    | way into their own booking and they will come back to it over weeks —
    | and short by the standards of a password, because it is a bearer token
    | sent over WhatsApp and it will end up in a family group chat.
    |
    */

    'link_days' => (int) env('PORTAL_LINK_DAYS', 30),

    'session_hours' => (int) env('PORTAL_SESSION_HOURS', 12),

    /*
    |--------------------------------------------------------------------------
    | What the portal shows
    |--------------------------------------------------------------------------
    |
    | Only things backed by real records. The plan's §6.1 lists a flight
    | centre, learning progress, a Ziyarah companion and a packing checklist;
    | none of those has any data behind it, and a portal section that is
    | permanently empty — or worse, filled with plausible-looking invented
    | content — is how the fabricated social links reached the live site.
    |
    | They arrive as the records that feed them arrive.
    |
    */

    'sections' => [
        'readiness' => true,
        'payments' => true,
        'documents' => true,
        'itinerary' => true,
        'hotels' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads from the portal
    |--------------------------------------------------------------------------
    |
    | A customer can send their passport and their transfer slip. They cannot
    | pull a file back down: see App\Http\Middleware\PortalSession. A link
    | forwarded to a group chat must not be a passport scan in a group chat.
    |
    */

    'uploads' => [
        'enabled' => (bool) env('PORTAL_UPLOADS', true),
    ],

];
