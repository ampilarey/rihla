<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class PropertyPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'property';
    }
}
