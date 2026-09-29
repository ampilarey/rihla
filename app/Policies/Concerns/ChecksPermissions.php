<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\HostContext;
use App\Support\HostRole;
use Illuminate\Database\Eloquent\Model;

/**
 * Maps the five resource actions onto `<resource>.<action>` permissions.
 *
 * Each policy names its resource and inherits the rest, so adding a model
 * cannot ship a policy that forgets one method and quietly denies — or worse,
 * allows — that action.
 *
 * ## Inside the host panel — §16.6
 *
 * A policy may also name the {@see HostRole} ability each action
 * needs at a host ({@see hostAbility()}). Inside `/host` that — and whether the
 * record is the current host's — is the whole answer; staff permissions are
 * never consulted there, and a policy that names no ability denies. Outside
 * `/host` nothing about this trait has changed.
 */
trait ChecksPermissions
{
    abstract protected function resource(): string;

    /**
     * The host ability an action needs in `/host`, or null to refuse it
     * there. Denies everything unless a policy says otherwise.
     */
    protected function hostAbility(string $action): ?string
    {
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->resolveAbility($user, 'viewAny');
    }

    public function view(User $user, ?Model $record = null): bool
    {
        return $this->resolveAbility($user, 'view', $record);
    }

    public function create(User $user): bool
    {
        return $this->resolveAbility($user, 'create');
    }

    public function update(User $user, ?Model $record = null): bool
    {
        return $this->resolveAbility($user, 'update', $record);
    }

    public function delete(User $user, ?Model $record = null): bool
    {
        return $this->resolveAbility($user, 'delete', $record);
    }

    private function resolveAbility(User $user, string $action, ?Model $record = null): bool
    {
        $host = HostContext::current();

        if ($host !== null) {
            $ability = $this->hostAbility($action);

            return $ability !== null && HostContext::allows($user, $host, $ability, $record);
        }

        return $user->can($this->resource().'.'.$action);
    }
}
