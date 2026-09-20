<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class CustomerPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'customer';
    }
}
