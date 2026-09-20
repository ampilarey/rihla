<?php

namespace App\Filament\Resources\Costs\Pages;

use App\Filament\Resources\Costs\DepartureCostResource;
use Filament\Resources\Pages\EditRecord;

/** No delete: a cost is corrected, never removed. */
class EditDepartureCost extends EditRecord
{
    protected static string $resource = DepartureCostResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
