<?php

namespace Modules\Umrah\Filament\Resources\Excursions\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Excursions\ExcursionResource;

class ListExcursions extends ListRecords
{
    protected static string $resource = ExcursionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add an additional trip')];
    }
}
