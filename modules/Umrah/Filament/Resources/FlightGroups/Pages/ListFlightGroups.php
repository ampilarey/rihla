<?php

namespace Modules\Umrah\Filament\Resources\FlightGroups\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\FlightGroups\FlightGroupResource;

class ListFlightGroups extends ListRecords
{
    protected static string $resource = FlightGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a flight group')];
    }
}
