<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

class MediaPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'media';
    }
}
