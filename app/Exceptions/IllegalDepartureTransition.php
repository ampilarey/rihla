<?php

namespace App\Exceptions;

use App\Models\Departure;
use RuntimeException;

class IllegalDepartureTransition extends RuntimeException
{
    public static function from(Departure $departure, string $to): self
    {
        $allowed = Departure::TRANSITIONS[$departure->status] ?? [];

        return new self(sprintf(
            'A departure that is %s cannot become %s. Allowed from here: %s.',
            Departure::label($departure->status),
            Departure::label($to),
            $allowed === [] ? 'nothing — it is a final state' : implode(', ', array_map(Departure::label(...), $allowed)),
        ));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
