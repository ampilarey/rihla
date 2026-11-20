<?php

namespace Modules\Umrah\Filament\Resources\Costs\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Umrah\Filament\Resources\Costs\DepartureCostResource;

class CreateDepartureCost extends CreateRecord
{
    protected static string $resource = DepartureCostResource::class;

    protected static ?string $title = 'Add a cost';
}
