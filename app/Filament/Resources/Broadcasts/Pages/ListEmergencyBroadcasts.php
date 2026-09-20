<?php

namespace App\Filament\Resources\Broadcasts\Pages;

use App\Filament\Resources\Broadcasts\EmergencyBroadcastResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmergencyBroadcasts extends ListRecords
{
    protected static string $resource = EmergencyBroadcastResource::class;

    protected static ?string $title = 'Emergency broadcast';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Write a broadcast')];
    }
}
