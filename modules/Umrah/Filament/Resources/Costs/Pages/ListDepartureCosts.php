<?php

namespace Modules\Umrah\Filament\Resources\Costs\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Costs\DepartureCostResource;

class ListDepartureCosts extends ListRecords
{
    protected static string $resource = DepartureCostResource::class;

    protected static ?string $title = 'Journey costs';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a cost')];
    }
}
