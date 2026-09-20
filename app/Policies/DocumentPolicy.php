<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `document.delete` does not exist, so the inherited delete() denies
 * everybody — which is [R-8]'s whole point. Every version is kept, and a
 * wallet somebody can quietly empty is not an audit trail.
 */
class DocumentPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'document';
    }

    /**
     * Pulling the file, which is a different disclosure from seeing that it
     * exists. Pilgrim Support can tell a caller "yes, we have your
     * passport"; it cannot put the scan in a downloads folder.
     */
    public function download(User $user): bool
    {
        return $user->can('document.download');
    }
}
