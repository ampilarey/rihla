<?php

namespace App\Exceptions;

use RuntimeException;

class GatewayNotConfigured extends RuntimeException
{
    public static function bml(): self
    {
        return new self(
            'Card payments are not connected. BML Connect needs a merchant account and '
            .'credentials in config/payments.php (BML_API_KEY, BML_APP_ID) before this '
            .'driver can take money. Nothing has been charged.',
        );
    }
}
