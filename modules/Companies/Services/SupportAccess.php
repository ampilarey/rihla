<?php

namespace Modules\Companies\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyRole;
use Illuminate\Support\Facades\DB;
use Modules\Companies\Models\SupportGrant;

/**
 * Support access with consent — companies plan, Phase 5c.
 *
 * Rihla asks; the company's Owner decides. A grant lets one named person
 * look (every view permission, nothing that changes anything) for the hours
 * the Owner chose, and can be revoked at any time. Every page they open is
 * recorded where the company sees it. There is no path by which Rihla grants
 * itself access.
 */
final class SupportAccess
{
    /** The lengths an Owner may grant, in hours. */
    public const HOURS = [1, 4, 24];

    public function ask(Partner $company, User $requester, string $reason): SupportGrant
    {
        if (! $company->is_umrah_company || $company->is_rihla) {
            throw new DeskRefusal('Support access is asked of another Umrah company.');
        }

        if (trim($reason) === '') {
            throw new DeskRefusal('Say why: the company decides from what you write.');
        }

        $grant = new SupportGrant(['reason' => trim($reason)]);
        $grant->company_id = (int) $company->getKey();
        $grant->requested_by = (int) $requester->getKey();
        $grant->save();

        return $grant;
    }

    public function grant(SupportGrant $grant, User $owner, int $hours): void
    {
        $this->assertOwner($grant, $owner);

        if (! in_array($hours, self::HOURS, true)) {
            throw new DeskRefusal('Choose one of the lengths offered.');
        }

        if ($grant->state() !== SupportGrant::PENDING) {
            throw new DeskRefusal('This request has already been answered.');
        }

        $grant->forceFill(['decided_by' => $owner->getKey(), 'granted_at' => now(), 'expires_at' => now()->addHours($hours)])->save();
    }

    public function decline(SupportGrant $grant, User $owner): void
    {
        $this->assertOwner($grant, $owner);

        if ($grant->state() !== SupportGrant::PENDING) {
            throw new DeskRefusal('This request has already been answered.');
        }

        $grant->forceFill(['decided_by' => $owner->getKey(), 'declined_at' => now()])->save();
    }

    public function revoke(SupportGrant $grant, User $owner): void
    {
        $this->assertOwner($grant, $owner);
        $grant->forceFill(['revoked_at' => now()])->save();
    }

    /** The live grant that lets this person into this company now, if any. */
    public static function liveFor(Partner $company, User $user): ?SupportGrant
    {
        return SupportGrant::query()
            ->live()
            ->where('company_id', $company->getKey())
            ->where('requested_by', $user->getKey())
            ->latest('granted_at')
            ->first();
    }

    private function assertOwner(SupportGrant $grant, User $owner): void
    {
        $company = $grant->company;

        // Asked directly of the membership table: a support visitor's role
        // reads as Support, never Owner, so they cannot grant themselves.
        if ($company === null || DB::table('company_memberships')->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->whereNotNull('accepted_at')->value('role') !== CompanyRole::OWNER) {
            throw new DeskRefusal('Only the company\'s Owner decides support access.');
        }
    }
}
