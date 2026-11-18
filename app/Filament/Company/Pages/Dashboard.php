<?php

namespace App\Filament\Company\Pages;

use App\Models\Partner;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * A company's first screen — companies plan, Phase 2a.
 *
 * Who you are signed in as, for which company, and in which role. The
 * screens a company runs its trips on arrive in Phase 2b.
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.company.dashboard';

    public function company(): Partner
    {
        /** @var Partner */
        return Filament::getTenant();
    }

    public function role(): string
    {
        return auth()->user()?->roleAtCompany($this->company()) ?? '';
    }
}
