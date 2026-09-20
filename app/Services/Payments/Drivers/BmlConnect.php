<?php

namespace App\Services\Payments\Drivers;

use App\Exceptions\GatewayNotConfigured;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\HandlesCallbacks;
use App\Services\Payments\PaymentGateway;
use App\Support\Money;

/**
 * Card payments through BML Connect — **a seam, not an implementation**.
 *
 * Rihla has no BML merchant account yet. Merchant onboarding is the longest
 * external lead time in this phase and it gates every card payment, so the
 * shape is here and the behaviour is not: connecting it should be a
 * configuration change and a filled-in method, not a rewrite of everything
 * above it.
 *
 * **It refuses loudly rather than pretending.** A driver that quietly
 * returned a pending payment would put a "paid by card" row in front of
 * staff for money nobody took. `isAvailable()` is false while the
 * credentials are absent, so the method is never offered; if something
 * calls it anyway, it throws and says exactly what is missing.
 *
 * The callback shape is stated here because the idempotency guarantee it
 * relies on is already real: `payment_transactions` is unique on
 * `(provider, provider_event_id)`, and that constraint is tested.
 */
final class BmlConnect implements HandlesCallbacks, PaymentGateway
{
    public const PROVIDER = 'bml';

    public function key(): string
    {
        return 'card';
    }

    /**
     * False until somebody has actually onboarded.
     *
     * Both the switch and the credentials, because either one alone is a
     * half-configured gateway: an enabled flag with no API key would offer
     * a button that throws.
     */
    public function isAvailable(): bool
    {
        return (bool) config('payments.methods.card.enabled', false)
            && filled(config('payments.bml.api_key'))
            && filled(config('payments.bml.app_id'));
    }

    /** @return array<string, string|null> */
    public function instructions(Money $amount): array
    {
        return ['amount' => $amount->format()];
    }

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws GatewayNotConfigured
     */
    public function start(Booking $booking, Money $amount, array $details = []): Payment
    {
        throw GatewayNotConfigured::bml();
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws GatewayNotConfigured
     */
    public function handleCallback(Payment $payment, array $event): void
    {
        throw GatewayNotConfigured::bml();
    }
}
