<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * No delete verb. A cost somebody entered and then thought better of is
 * corrected, not removed: a margin that changed because a row vanished is
 * one nobody can explain afterwards.
 */
class DepartureCostPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'cost';
    }
}
