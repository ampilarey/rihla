<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class StayPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'stay';
    }
}
