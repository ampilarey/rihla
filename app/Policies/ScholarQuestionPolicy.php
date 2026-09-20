<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;

/**
 * Nobody creates a question in the office — a pilgrim does — and nobody
 * deletes one, because a question that vanished is one the person who asked
 * it is still waiting on. Both verbs are absent from `Access::PERMISSIONS`,
 * so the inherited methods deny.
 */
class ScholarQuestionPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'question';
    }

    /** The scholar's. */
    public function answer(User $user): bool
    {
        return $user->can('question.answer');
    }

    /**
     * The office's, and gated on the asker's consent besides — which lives
     * on the record, not in this policy, because it is not a permission
     * anybody here can be granted.
     */
    public function publish(User $user): bool
    {
        return $user->can('question.publish');
    }
}
