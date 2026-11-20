<?php

namespace Modules\Umrah\Filament\Resources\Rooms\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Rooms\RoomResource;

class ListRooms extends ListRecords
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a room')];
    }
}
