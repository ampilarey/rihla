<?php

namespace App\Filament\Resources\Flights\Pages;

use App\Filament\Resources\Flights\DepartureFlightResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepartureFlights extends ListRecords
{
    protected static string $resource = DepartureFlightResource::class;

    protected static ?string $title = 'Flights';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a flight')];
    }
}
