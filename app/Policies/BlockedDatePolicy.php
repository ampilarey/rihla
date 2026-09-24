<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class BlockedDatePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'blockedDate';
    }
}
