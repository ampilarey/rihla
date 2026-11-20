<?php

namespace Modules\Content\Policies;

use App\Policies\Concerns\ChecksPermissions;

class TripPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'trip';
    }
}
