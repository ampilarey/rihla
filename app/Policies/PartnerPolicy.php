<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class PartnerPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'partner';
    }
}
