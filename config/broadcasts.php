<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Emergency broadcast channels (§6.5)
    |--------------------------------------------------------------------------
    |
    | Which channels a broadcast is attempted on, in order. `portal` is
    | first and is the only one that works on this host today: a notice
    | appears on the Pilgrim Portal and on every live family link with no
    | credentials at all.
    |
    | `email` needs real SMTP — MAIL_MAILER is `log` here, which is a valid
    | Laravel setup and a useless emergency channel. `sms` needs a provider
    | nobody has chosen; in the Maldives that is a contract, not a config
    | line, and guessing one would mean writing an integration against an
    | API this operator may never buy.
    |
    | Both are listed anyway. An unavailable channel records *why* against
    | every recipient, so the answer to "did her family get this" is a
    | sentence rather than a silence.
    |
    */

    'channels' => ['portal', 'email', 'sms'],

    /*
    | Filled in when somebody signs with a provider. Until then SmsChannel
    | reports itself unavailable and says so.
    */

    'sms' => [
        'provider' => env('BROADCAST_SMS_PROVIDER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Emergency contacts (§6.5)
    |--------------------------------------------------------------------------
    |
    | Whether a departure is held back when a confirmed traveller has no
    | emergency contact on file. On by default: the moment this matters is
    | the moment nobody has time to go looking for a phone number.
    |
    */

    'require_emergency_contacts' => (bool) env('REQUIRE_EMERGENCY_CONTACTS', true),

];
