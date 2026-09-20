<?php

namespace App\Services\Assistant;

/**
 * The model behind the pilgrim assistant — §9.6.
 *
 * An interface because the provider is a configuration decision and not an
 * architectural one, and because {@see PilgrimAssistant}'s guarantees must
 * hold whichever model answers: the constraints live above this line, in
 * code, not in a system prompt any provider might weigh differently.
 */
interface Provider
{
    /** Whether a key and a model have actually been set. */
    public function isConfigured(): bool;

    /** What to call it in the exchange log. */
    public function name(): string;

    public function model(): string;

    /**
     * One completion, or null when the call failed.
     *
     * Null rather than an exception: a provider that is down must produce
     * a referral to a human, not a stack trace on a pilgrim's telephone.
     */
    public function complete(string $system, string $question): ?string;
}
