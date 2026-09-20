<?php

namespace App\Exceptions;

use App\Models\NusukPermit;
use RuntimeException;

class IllegalPermitTransition extends RuntimeException
{
    public static function from(NusukPermit $permit, string $to): self
    {
        $allowed = NusukPermit::TRANSITIONS[$permit->status] ?? [];

        return new self(sprintf(
            'A %s %s permit cannot become %s. Allowed from here: %s.',
            $permit->status,
            $permit->kind,
            $to,
            $allowed === [] ? 'nothing — it is a final status, and a retry is a new attempt' : implode(', ', $allowed),
        ));
    }
}
