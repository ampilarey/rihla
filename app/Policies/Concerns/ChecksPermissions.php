<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Maps the five resource actions onto `<resource>.<action>` permissions.
 *
 * Each policy names its resource and inherits the rest, so adding a model
 * cannot ship a policy that forgets one method and quietly denies — or worse,
 * allows — that action.
 */
trait ChecksPermissions
{
    abstract protected function resource(): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->resource().'.viewAny');
    }

    public function view(User $user): bool
    {
        return $user->can($this->resource().'.view');
    }

    public function create(User $user): bool
    {
        return $user->can($this->resource().'.create');
    }

    public function update(User $user): bool
    {
        return $user->can($this->resource().'.update');
    }

    public function delete(User $user): bool
    {
        return $user->can($this->resource().'.delete');
    }
}
