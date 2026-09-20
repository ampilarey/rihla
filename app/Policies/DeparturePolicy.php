<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class DeparturePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'departure';
    }
}
