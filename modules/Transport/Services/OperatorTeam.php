<?php

namespace Modules\Transport\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Transport\Models\OperatorMembership;

/**
 * Who works for a boat operator — docs/TRANSPORT_PLAN.md (T5). Rihla's
 * staff add an operator's first owner; an owner adds their own crew. A
 * person with no account gets one with a password nobody knows and the
 * standard "set your password" e-mail, so no password is ever chosen for
 * them or shown on a screen.
 */
final class OperatorTeam
{
    public function add(Partner $operator, string $name, string $email, string $role, ?User $by): OperatorMembership
    {
        if ($operator->kind !== Partner::KIND_OPERATOR) {
            throw new DeskRefusal('Only an outside boat operator has a panel; Rihla\'s own crew use the staff panel.');
        }

        if (! array_key_exists($role, OperatorMembership::ROLES)) {
            throw new DeskRefusal('Choose owner or crew.');
        }

        $email = Str::lower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DeskRefusal('That is not an e-mail address.');
        }

        [$membership, $isNew] = DB::transaction(function () use ($operator, $name, $email, $role, $by): array {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            $isNew = $user === null;

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => trim($name) !== '' ? trim($name) : $email,
                    'email' => $email,
                    'password' => Hash::make(Str::random(48)),
                ])->save();
            }

            $membership = OperatorMembership::query()->where('partner_id', $operator->getKey())->where('user_id', $user->getKey())->first() ?? new OperatorMembership;
            $membership->forceFill([
                'partner_id' => $operator->getKey(),
                'user_id' => $user->getKey(),
                'role' => $role,
                'accepted_at' => $membership->accepted_at ?? now(),
                'added_by' => $membership->added_by ?? $by?->getKey(),
            ])->save();

            return [$membership, $isNew];
        });

        if ($isNew) {
            Password::sendResetLink(['email' => $email]);
        }

        return $membership;
    }

    public function remove(OperatorMembership $membership): void
    {
        $membership->delete();
    }
}
