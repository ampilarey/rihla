<?php

namespace Modules\Umrah\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * An additional trip is part of planning a departure (companies plan,
 * Phase 3e), so it reads the `departure.*` permissions.
 */
class DepartureExcursionPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'departure';
    }
}
