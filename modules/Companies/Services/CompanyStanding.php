<?php

namespace Modules\Companies\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;

/**
 * Suspending and reactivating a company — companies plan, Phase 5b.
 *
 * A suspension shuts the company's people out of its panel, takes its site
 * and portals down and silences its bot, all of which already read
 * `isSuspended()`. Nothing is deleted, and reactivating puts everything
 * back as it was. The reason is kept with the company and, like every
 * change to a partner, the audit trail records who did it and when.
 */
final class CompanyStanding
{
    public function suspend(Partner $company, string $reason): void
    {
        $this->assertCompany($company);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DeskRefusal('Say why: the company will ask, and the next person at Rihla will need to know.');
        }

        $company->forceFill([
            'status' => Partner::STATUS_SUSPENDED,
            'suspended_reason' => $reason,
            'suspended_at' => now(),
        ])->save();
    }

    /**
     * Close the account (Phase 5d): everything off, as for a suspension,
     * and nothing deleted. The reason is kept where a suspension's is.
     */
    public function close(Partner $company, string $reason): void
    {
        $this->assertCompany($company);

        if (trim($reason) === '') {
            throw new DeskRefusal('Say why the account is closing.');
        }

        $company->forceFill([
            'status' => Partner::STATUS_CLOSED,
            'suspended_reason' => trim($reason),
            'suspended_at' => now(),
            'site_enabled' => false,
        ])->save();
    }

    /** Back to active, from suspended or closed. A closed site stays off until switched on again. */
    public function reactivate(Partner $company): void
    {
        $this->assertCompany($company);

        $company->forceFill([
            'status' => Partner::STATUS_ACTIVE,
            'suspended_reason' => null,
            'suspended_at' => null,
        ])->save();
    }

    private function assertCompany(Partner $company): void
    {
        if (! $company->is_umrah_company || $company->is_rihla) {
            throw new DeskRefusal('Only another Umrah company can be suspended here.');
        }
    }
}
