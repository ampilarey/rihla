<?php

namespace Modules\Content\Filament\Resources\People\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\People\PersonResource;

class ListPeople extends ListRecords
{
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
