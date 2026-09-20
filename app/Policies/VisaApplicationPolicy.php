<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `visa.delete` does not exist, so the inherited delete() denies everybody.
 * An application is a record of something submitted to a government, and a
 * refusal that can be removed is evidence that can be removed.
 */
class VisaApplicationPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'visa';
    }
}
