<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who a piece of work can be handed to: the options of every "whose job",
 * "assign to" and "officer" list (security review before the pilot).
 *
 * Inside a company's panel the answer is that company's own accepted
 * members, never anyone else's: those lists used to read `users` whole,
 * so a company saw the name of every account in the system and could hand
 * its work to another company's staff — and the role-filtered ones read
 * spatie roles, which a company's people do not hold, so they listed only
 * Rihla's staff. A role filter there reads the person's role *at the
 * company*, and the Owner always counts, as the Owner holds every area.
 *
 * For Rihla the answer is exactly what each list asked before.
 */
final class Assignees
{
    /**
     * @param  list<string>|null  $roles  null for anybody
     * @return array<int|string, string> id => name
     */
    public static function options(?array $roles = null): array
    {
        $company = CompanyRequest::current();

        if ($company === null) {
            $query = $roles === null ? User::query()->whereNotNull('name') : User::role($roles);

            return $query->orderBy('name')->pluck('name', 'id')->all();
        }

        $members = DB::table('company_memberships')
            ->where('company_id', $company->getKey())
            ->whereNotNull('accepted_at')
            ->when($roles !== null, fn ($q) => $q->whereIn('role', [...$roles, CompanyRole::OWNER]))
            ->pluck('user_id');

        return User::query()->whereIn('id', $members)->orderBy('name')->pluck('name', 'id')->all();
    }
}
