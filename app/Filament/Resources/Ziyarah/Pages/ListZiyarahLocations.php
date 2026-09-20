<?php

namespace App\Filament\Resources\Ziyarah\Pages;

use App\Filament\Resources\Ziyarah\ZiyarahLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListZiyarahLocations extends ListRecords
{
    protected static string $resource = ZiyarahLocationResource::class;

    protected static ?string $title = 'Ziyarah Guide';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a location')];
    }
}
