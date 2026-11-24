<?php

namespace Modules\Transport\Filament\Resources\Vessels\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Transport\Filament\Resources\Vessels\VesselResource;

class ManageVessels extends ManageRecords
{
    protected static string $resource = VesselResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a boat')];
    }
}
