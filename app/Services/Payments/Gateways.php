<?php

namespace App\Services\Payments;

use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\Drivers\BmlConnect;
use App\Services\Payments\Drivers\CashAtTheOffice;
use InvalidArgumentException;

/**
 * Which ways of paying exist, and which can be offered right now.
 *
 * One place that knows the drivers, so that adding a method is adding a
 * class and a config entry rather than touching every screen that lists
 * them.
 *
 * `available()` and `all()` are different questions on purpose. A card
 * driver with no merchant account still exists — it is in `all()`, it has a
 * label, and a staff screen can say why it is off — but it is not in
 * `available()` and is never offered to a customer.
 */
final class Gateways
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const DRIVERS = [
        'bank_transfer' => BankTransfer::class,
        'cash' => CashAtTheOffice::class,
        'card' => BmlConnect::class,
    ];

    /** @return array<string, PaymentGateway> keyed by method */
    public function all(): array
    {
        $gateways = [];

        foreach (self::DRIVERS as $method => $class) {
            $gateways[$method] = app($class);
        }

        return $gateways;
    }

    /** @return array<string, PaymentGateway> */
    public function available(): array
    {
        return array_filter(
            $this->all(),
            fn (PaymentGateway $gateway): bool => $gateway->isAvailable(),
        );
    }

    /**
     * @throws InvalidArgumentException when the method is not one this
     *                                  application knows — a typo, or a
     *                                  request body somebody edited.
     */
    public function for(string $method): PaymentGateway
    {
        $class = self::DRIVERS[$method] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException(sprintf(
                'There is no "%s" payment method. Known: %s.',
                $method,
                implode(', ', array_keys(self::DRIVERS)),
            ));
        }

        return app($class);
    }

    /**
     * Method => label, for the ways that can actually be used.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->available() as $method => $gateway) {
            $options[$method] = (string) config("payments.methods.{$method}.label", $method);
        }

        return $options;
    }
}
