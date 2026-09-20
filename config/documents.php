<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Passport validity window
    |--------------------------------------------------------------------------
    |
    | How long a passport must still be valid for, measured from the travel
    | date, before it stops being a problem. Six months is the figure every
    | Saudi checklist quotes.
    |
    | Configuration rather than a constant, because the plan (§5.4b) is
    | explicit that **all Saudi requirements live in versioned configuration**
    | — passport validity, prerequisites, permitted visa types, slot lead
    | times — so that a mid-season policy change is a config edit and not a
    | deployment. This is the first of them.
    |
    */

    'passport_validity_months' => (int) env('PASSPORT_VALIDITY_MONTHS', 6),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | The private disk, and what may be put on it. Images and PDFs only:
    | these are scans and photographs of documents, and anything else arriving
    | here is a mistake or an attack.
    |
    */

    'disk' => env('DOCUMENTS_DISK', 'documents'),

    'max_kilobytes' => (int) env('DOCUMENTS_MAX_KB', 8192),

    'mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/webp'],

    /*
    |--------------------------------------------------------------------------
    | Download links
    |--------------------------------------------------------------------------
    |
    | How long a signed download URL stays good. Short, because the link is
    | the only thing between a passport scan and whoever ends up holding the
    | URL — a chat history, a proxy log, a screenshot.
    |
    */

    'download_link_minutes' => (int) env('DOCUMENTS_LINK_MINUTES', 5),

];
