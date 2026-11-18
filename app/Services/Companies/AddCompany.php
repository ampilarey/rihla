<?php

namespace App\Services\Companies;

use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Rihla's admin adds an Umrah company and its owner — companies plan,
 * decisions 6 and 17.
 *
 * There is no public sign-up: a person at Rihla has checked the licence
 * before this runs, so the company starts verified and active, with that
 * person recorded as the one who checked. The owner is an ordinary user,
 * made if their address is new, given the Owner role at the company and
 * sent the usual "choose your password" e-mail; Rihla never sees or sets a
 * password for them.
 */
final class AddCompany
{
    public function __invoke(string $name, string $ownerName, string $ownerEmail, ?User $checkedBy = null): Partner
    {
        $email = Str::lower(trim($ownerEmail));

        [$company, $owner, $isNew] = DB::transaction(function () use ($name, $ownerName, $email, $checkedBy): array {
            $company = new Partner(['name' => $name, 'slug' => $this->slugFor($name), 'email' => $email, 'is_active' => true]);
            $company->forceFill([
                'is_umrah_company' => true,
                'verification' => Partner::VERIFIED,
                'verified_at' => now(),
                'verified_by' => $checkedBy?->getKey(),
                'status' => Partner::STATUS_ACTIVE,
            ])->save();

            $owner = User::where('email', $email)->first();
            $isNew = $owner === null;
            $owner ??= User::forceCreate([
                'name' => $ownerName,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);

            DB::table('company_memberships')->insert([
                'company_id' => $company->getKey(),
                'user_id' => $owner->getKey(),
                'role' => CompanyRole::OWNER,
                'invited_by' => $checkedBy?->getKey(),
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [$company, $owner, $isNew];
        });

        // After the commit, so a failed send never leaves half a company.
        // Somebody who already has a login keeps their password.
        if ($isNew) {
            Password::broker()->sendResetLink(['email' => $owner->email]);
        }

        return $company;
    }

    /** A slug no other partner has, from the company's name. */
    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;

        for ($n = 2; Partner::where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
