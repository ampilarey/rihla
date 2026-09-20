<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `permit.delete` does not exist, so the inherited delete() denies
 * everybody: a refusal that can be removed is evidence that can be removed.
 */
class NusukPermitPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'permit';
    }
}
