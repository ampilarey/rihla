<?php

namespace App\Exceptions;

use App\Models\VisaApplication;
use RuntimeException;

class IllegalVisaTransition extends RuntimeException
{
    public static function from(VisaApplication $application, string $to): self
    {
        $allowed = VisaApplication::TRANSITIONS[$application->status] ?? [];

        return new self(sprintf(
            'A %s visa application cannot become %s. Allowed from here: %s.',
            $application->status,
            $to,
            $allowed === [] ? 'nothing — it is a final status, and a retry is a new attempt' : implode(', ', $allowed),
        ));
    }
}
