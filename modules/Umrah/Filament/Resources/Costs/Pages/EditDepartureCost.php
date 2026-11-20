<?php

namespace Modules\Umrah\Filament\Resources\Costs\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Umrah\Filament\Resources\Costs\DepartureCostResource;

/** No delete: a cost is corrected, never removed. */
class EditDepartureCost extends EditRecord
{
    protected static string $resource = DepartureCostResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
