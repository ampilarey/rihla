<?php

namespace Modules\Umrah\Filament\Resources\BusGroups\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\BusGroups\BusGroupResource;

class ListBusGroups extends ListRecords
{
    protected static string $resource = BusGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a bus')];
    }
}
