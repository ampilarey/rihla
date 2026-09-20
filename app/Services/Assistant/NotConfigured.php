<?php

namespace App\Services\Assistant;

/**
 * The provider when nobody has set a key — §9.6.
 *
 * This is the state Rihla is in today, and it is a deliberate state rather
 * than a broken one: every question goes to a human with a sentence saying
 * why. There is no silent fallback to a model that would make something up.
 */
final class NotConfigured implements Provider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function model(): string
    {
        return 'none';
    }

    public function complete(string $system, string $question): ?string
    {
        return null;
    }
}
