<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The staff bot — batch A4 from Hijaz
    |--------------------------------------------------------------------------
    |
    | From @BotFather. Lives only in the server .env; empty switches the bot
    | off, and the webhook answers 404. Set it on ONE site: Telegram allows
    | one webhook per bot and the deploy script registers this site's, so
    | production and test need two bots or the second deploy steals it.
    |
    */

    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // Without the @. Used for the t.me deep link that links a staff
    // account; asked of Telegram and cached when not set.
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Telegram sends this back in X-Telegram-Bot-Api-Secret-Token on every
    // webhook call. Derived from the app key when not set, so no extra
    // secret has to be managed.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    // Minutes a link code stays valid.
    'link_code_minutes' => 10,

    // Local time for the morning summary.
    'daily_summary_at' => env('TELEGRAM_DAILY_SUMMARY_AT', '08:00'),

];
