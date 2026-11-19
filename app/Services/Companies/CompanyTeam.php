<?php

namespace App\Services\Companies;

use App\Exceptions\DeskRefusal;
use App\Models\CompanyMembership;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyRole;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Who works for an Umrah company — companies plan, Phase 2c.
 *
 * The owner adds people by e-mail with a role. Somebody new is made a user
 * and sent the usual "choose your password" e-mail, as the owner was; a
 * person who already has a login is simply added and keeps their password.
 * Roles change and people leave, and a company always keeps an Owner: the
 * last one can be neither demoted nor removed, or nobody could manage the
 * team again.
 */
final class CompanyTeam
{
    public function add(Partner $company, string $name, string $email, string $role, ?User $by = null, ?string $phone = null): CompanyMembership
    {
        $this->assertRole($role);
        $email = Str::lower(trim($email));
        // A mobile number to sign in with (Phase 3i), for a new account only:
        // somebody who already has one keeps the number they chose.
        $phoneKey = PhoneNumber::key($phone);

        if ($phoneKey !== null && User::query()->where('phone', $phoneKey)->where('email', '!=', $email)->exists()) {
            throw new DeskRefusal('Somebody else already signs in with that mobile number.');
        }

        [$membership, $isNew] = DB::transaction(function () use ($company, $name, $email, $role, $by, $phoneKey): array {
            $user = User::where('email', $email)->first();
            $isNew = $user === null;
            $user ??= User::forceCreate([
                'name' => $name,
                'email' => $email,
                'phone' => $phoneKey,
                'password' => Hash::make(Str::random(40)),
            ]);

            if (CompanyMembership::where('company_id', $company->getKey())->where('user_id', $user->getKey())->exists()) {
                throw new DeskRefusal("{$email} is already on the team.");
            }

            $membership = CompanyMembership::create([
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
                'role' => $role,
                'invited_by' => $by?->getKey(),
                'accepted_at' => now(),
            ]);

            return [$membership, $isNew];
        });

        if ($isNew) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        return $membership;
    }

    public function changeRole(CompanyMembership $membership, string $role): void
    {
        $this->assertRole($role);

        if ($membership->role === CompanyRole::OWNER && $role !== CompanyRole::OWNER) {
            $this->assertAnotherOwner($membership);
        }

        $membership->update(['role' => $role]);
    }

    public function remove(CompanyMembership $membership): void
    {
        if ($membership->role === CompanyRole::OWNER) {
            $this->assertAnotherOwner($membership);
        }

        $membership->delete();
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, CompanyRole::ROLES, true)) {
            throw new DeskRefusal('That is not a role a company can give.');
        }
    }

    private function assertAnotherOwner(CompanyMembership $membership): void
    {
        $others = CompanyMembership::where('company_id', $membership->company_id)
            ->where('role', CompanyRole::OWNER)
            ->whereNotNull('accepted_at')
            ->whereKeyNot($membership->getKey())
            ->exists();

        if (! $others) {
            throw new DeskRefusal('A company always keeps an Owner. Make somebody else Owner first.');
        }
    }
}
