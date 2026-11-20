<?php

namespace Modules\Companies\Filament\Pages;

use App\Models\Partner;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * A company's first screen — companies plan, Phase 2a; its figures and
 * charts from Phase 3g.
 *
 * Who you are signed in as, for which company and in which role, and then
 * the same cards and charts as Rihla's own home page, each behind its
 * permission and counting only this company's records.
 */
class Dashboard extends BaseDashboard
{
    public function company(): Partner
    {
        /** @var Partner */
        return Filament::getTenant();
    }

    public function role(): string
    {
        return auth()->user()?->roleAtCompany($this->company()) ?? '';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make($this->company()->name)
                ->description('You are signed in as '.$this->role().'.')
                ->schema([Text::make(__('Everything your team works on is in the menu: bookings, payments, documents and the journey.'))]),
            $this->getWidgetsContentComponent(),
        ]);
    }
}
