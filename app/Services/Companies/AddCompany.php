<?php

namespace App\Services\Companies;

use App\Models\CompanySite;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyFeatures;
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
    /**
     * @param  array<string, string>|null  $features  what the company has paid for (Phase 5a); null is
     *                                                everything, for callers that predate features. The
     *                                                admin's screen always passes exactly what was ticked.
     * @param  array{licence_number?: ?string, licence_expires_on?: mixed, licence_register?: ?string, registration_fee_minor?: ?int, registration_fee_currency?: ?string, staff_seats?: ?int}  $terms
     *                                                                                                                                                                                                  the licence checked and what was agreed (Phase 5b)
     */
    public function __invoke(string $name, string $ownerName, string $ownerEmail, ?User $checkedBy = null, ?array $features = null, array $terms = []): Partner
    {
        $email = Str::lower(trim($ownerEmail));
        $features = $features === null
            ? array_fill_keys(array_keys(CompanyFeatures::FEATURES), CompanyFeatures::ON)
            : CompanyFeatures::clean($features);

        [$company, $owner, $isNew] = DB::transaction(function () use ($name, $ownerName, $email, $checkedBy, $features, $terms): array {
            $company = new Partner(['name' => $name, 'slug' => $this->slugFor($name), 'email' => $email, 'is_active' => true]);
            $company->forceFill([
                'is_umrah_company' => true,
                'verification' => Partner::VERIFIED,
                'verified_at' => now(),
                'verified_by' => $checkedBy?->getKey(),
                'status' => Partner::STATUS_ACTIVE,
                'features' => $features,
                // The licence checked and what was agreed (Phase 5b).
                'licence_number' => $terms['licence_number'] ?? null,
                'licence_expires_on' => $terms['licence_expires_on'] ?? null,
                'licence_register' => $terms['licence_register'] ?? null,
                'registration_fee_minor' => $terms['registration_fee_minor'] ?? null,
                'registration_fee_currency' => isset($terms['registration_fee_minor']) ? ($terms['registration_fee_currency'] ?? 'MVR') : null,
                'staff_seats' => $terms['staff_seats'] ?? null,
            ])->save();

            // Defaults seeded: an unpublished website for the owner to fill
            // in, so the Website page opens on something rather than nothing.
            $site = new CompanySite;
            $site->company_id = (int) $company->getKey();
            $site->save();

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
