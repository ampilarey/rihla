<?php

namespace Modules\Content\Policies;

use App\Policies\Concerns\ChecksPermissions;

class HeroBannerPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'heroBanner';
    }
}
