<?php

namespace App\Filament\Resources\FlightGroups\Pages;

use App\Filament\Resources\FlightGroups\FlightGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightGroups extends ListRecords
{
    protected static string $resource = FlightGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a flight group')];
    }
}
