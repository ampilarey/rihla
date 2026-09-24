<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class RatePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'rate';
    }
}
