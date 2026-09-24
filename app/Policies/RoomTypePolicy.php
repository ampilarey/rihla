<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class RoomTypePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'roomType';
    }
}
