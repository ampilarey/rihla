<?php

namespace App\Exceptions;

use App\Models\Departure;
use RuntimeException;

/**
 * §5.4b's gate: Nusuk wants accommodation and transport recorded before a
 * permit can be requested.
 *
 * Thrown rather than silently allowed, because a request that will be
 * refused for a reason we could have seen costs a round trip and leaves a
 * status nobody can explain.
 *
 * @see NusukPermit::missingPrerequisites()
 */
class PrerequisitesNotMet extends RuntimeException
{
    /** @param  list<string>  $missing */
    public static function on(Departure $departure, array $missing): self
    {
        return new self(sprintf(
            'Departure %d has no %s recorded in Nusuk. Record it before requesting permits.',
            $departure->getKey(),
            implode(' and no ', $missing),
        ));
    }
}
