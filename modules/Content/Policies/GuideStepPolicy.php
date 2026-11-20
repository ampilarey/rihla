<?php

namespace Modules\Content\Policies;

use App\Policies\Concerns\ChecksPermissions;

class GuideStepPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'guide';
    }
}
