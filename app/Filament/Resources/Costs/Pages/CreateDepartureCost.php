<?php

namespace App\Filament\Resources\Costs\Pages;

use App\Filament\Resources\Costs\DepartureCostResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartureCost extends CreateRecord
{
    protected static string $resource = DepartureCostResource::class;

    protected static ?string $title = 'Add a cost';
}
