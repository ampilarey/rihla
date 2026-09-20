<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class PersonPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'person';
    }
}
