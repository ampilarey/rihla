<?php

namespace Modules\Content\Filament\Resources\Ziyarah\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\Ziyarah\ZiyarahLocationResource;

class ListZiyarahLocations extends ListRecords
{
    protected static string $resource = ZiyarahLocationResource::class;

    protected static ?string $title = 'Ziyarah Guide';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a location')];
    }
}
