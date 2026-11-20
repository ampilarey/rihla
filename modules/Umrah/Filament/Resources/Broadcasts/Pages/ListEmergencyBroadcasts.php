<?php

namespace Modules\Umrah\Filament\Resources\Broadcasts\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Broadcasts\EmergencyBroadcastResource;

class ListEmergencyBroadcasts extends ListRecords
{
    protected static string $resource = EmergencyBroadcastResource::class;

    protected static ?string $title = 'Emergency broadcast';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Write a broadcast')];
    }
}
