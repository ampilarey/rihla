<?php

namespace App\Filament\Resources\BusGroups\Pages;

use App\Filament\Resources\BusGroups\BusGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBusGroups extends ListRecords
{
    protected static string $resource = BusGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a bus')];
    }
}
