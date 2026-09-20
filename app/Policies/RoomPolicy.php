<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `rooming.create` and `rooming.delete` do not exist, so the inherited
 * methods deny everybody. Adding a room and moving somebody between rooms
 * are both "changing the rooming list", and splitting them into separate
 * permissions would only ever be granted together.
 */
class RoomPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'rooming';
    }
}
