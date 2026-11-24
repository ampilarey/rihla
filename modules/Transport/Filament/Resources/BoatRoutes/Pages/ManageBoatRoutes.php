<?php

namespace Modules\Transport\Filament\Resources\BoatRoutes\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Transport\Filament\Resources\BoatRoutes\BoatRouteResource;

class ManageBoatRoutes extends ManageRecords
{
    protected static string $resource = BoatRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a route')];
    }
}
