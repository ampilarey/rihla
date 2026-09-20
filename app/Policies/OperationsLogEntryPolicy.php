<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `opslog.delete` does not exist, so the inherited delete() denies every
 * role checked against a permission. The log is the account of the trip;
 * an entry somebody regrets is corrected by writing the correction, the
 * way a ship's log is.
 */
class OperationsLogEntryPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'opslog';
    }
}
