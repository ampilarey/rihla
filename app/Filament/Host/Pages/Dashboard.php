<?php

namespace App\Filament\Host\Pages;

use App\Models\Partner;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The host's first screen — §16.6.
 *
 * Today's numbers arrive with the bookings screens (Phase 14.3). What it
 * says now is where the host stands with Rihla: a host nobody has checked
 * yet can build listings, but none of them is shown to a guest until a
 * person at Rihla has verified the registration (§16.3 decision 3).
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.host.dashboard';

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
