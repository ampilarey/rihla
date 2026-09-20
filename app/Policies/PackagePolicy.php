<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class PackagePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'package';
    }
}
