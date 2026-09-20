<?php

namespace App\Filament\Resources\Costs\Pages;

use App\Filament\Resources\Costs\DepartureCostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepartureCosts extends ListRecords
{
    protected static string $resource = DepartureCostResource::class;

    protected static ?string $title = 'Journey costs';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a cost')];
    }
}
