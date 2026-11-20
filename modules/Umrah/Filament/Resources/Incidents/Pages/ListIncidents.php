<?php

namespace Modules\Umrah\Filament\Resources\Incidents\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Incidents\IncidentResource;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Record an incident')];
    }
}
