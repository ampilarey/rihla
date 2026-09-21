<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The pilgrim assistant — §9.6
    |--------------------------------------------------------------------------
    |
    | Off by default, and off is not a degraded state: with this false the
    | assistant refuses every question and hands the asker to a human, which
    | is the behaviour §9.6 asks for whenever it cannot answer from
    | scholar-approved content.
    |
    | Turning it on is not sufficient to make it answer. The hard
    | constraints in App\Services\Assistant\PilgrimAssistant are in code,
    | not in the prompt: no approved source that matches the question means
    | no answer, whatever the key is set to.
    |
    */

    'enabled' => (bool) env('ASSISTANT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | The provider
    |--------------------------------------------------------------------------
    |
    | No key means not configured, and not configured means every question
    | goes to a human with a sentence saying why. There is no fallback to a
    | model that makes things up.
    |
    */

    'provider' => env('ASSISTANT_PROVIDER', 'anthropic'),

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ASSISTANT_MODEL', 'claude-sonnet-5'),
        'base_url' => env('ASSISTANT_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 900),
        'timeout' => (int) env('ASSISTANT_TIMEOUT', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    |
    | How many approved passages to put in front of the model, and how much
    | of each. Small on purpose: a long context is a long opportunity to
    | blend two rulings into a third that neither source makes.
    |
    */

    'max_sources' => (int) env('ASSISTANT_MAX_SOURCES', 4),

    'excerpt_characters' => (int) env('ASSISTANT_EXCERPT_CHARACTERS', 1200),

    /*
    |--------------------------------------------------------------------------
    | Retention of the exchange log
    |--------------------------------------------------------------------------
    |
    | §9.6 requires prompts and responses to be logged. A religious question
    | is sensitive, so the log is not kept for ever: `assistant:prune`
    | deletes exchanges older than this. Nothing runs it automatically —
    | there is no queue worker (ADR 0002) — so it belongs in the cPanel cron
    | beside the other maintenance commands.
    |
    */

    'log_retention_days' => (int) env('ASSISTANT_LOG_RETENTION_DAYS', 180),

];
