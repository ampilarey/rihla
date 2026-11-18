<?php

namespace App\Filament\Resources\Excursions\Pages;

use App\Filament\Resources\Excursions\ExcursionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExcursions extends ListRecords
{
    protected static string $resource = ExcursionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add an additional trip')];
    }
}
