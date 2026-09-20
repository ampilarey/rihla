<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * `knowledge.delete` does not exist. A withdrawn article keeps its reason;
 * a page that vanished for no recorded cause is one nobody can explain a
 * year later.
 */
class KnowledgeArticlePolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'knowledge';
    }

    /** A scholar's judgement about the content. */
    public function review(User $user): bool
    {
        return $user->can('knowledge.review');
    }

    /**
     * The office's decision about timing.
     *
     * Deliberately not held by whoever holds `review`: §6.4 makes
     * editorial review before publish non-negotiable, and a reviewer who
     * can also publish performs the check on themselves.
     */
    public function publish(User $user): bool
    {
        return $user->can('knowledge.publish');
    }
}
