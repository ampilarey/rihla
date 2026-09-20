<?php

namespace App\Filament\Resources\Ziyarah\Pages;

use App\Filament\Resources\Ziyarah\ZiyarahLocationResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Where the misconceptions and the sources live — the two things §7.2 is
 * actually about. A resource with no detail page would leave both
 * unreachable.
 */
class EditZiyarahLocation extends EditRecord
{
    protected static string $resource = ZiyarahLocationResource::class;

    /** No delete. A withdrawn location keeps its reason. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
