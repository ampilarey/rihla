<?php

namespace App\Filament\Host\Pages;

use App\Filament\Host\Widgets\Today;
use App\Models\Partner;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The host's first screen — §16.6.
 *
 * Today's numbers at the desk ({@see Today}), under where the host stands
 * with Rihla: a host nobody has checked
 * yet can build listings, but none of them is shown to a guest until a
 * person at Rihla has verified the registration (§16.3 decision 3).
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.host.dashboard';

    protected function getHeaderWidgets(): array
    {
        return [Today::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function host(): Partner
    {
        /** @var Partner */
        return Filament::getTenant();
    }

    public function standing(): string
    {
        $host = $this->host();

        return match (true) {
            $host->isSuspended() => 'suspended',
            $host->verification === Partner::REFUSED => 'refused',
            $host->verification !== Partner::VERIFIED || $host->status !== Partner::STATUS_ACTIVE => 'checking',
            default => 'live',
        };
    }
}
