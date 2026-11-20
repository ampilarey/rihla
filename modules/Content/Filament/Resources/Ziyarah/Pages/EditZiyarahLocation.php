<?php

namespace Modules\Content\Filament\Resources\Ziyarah\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Content\Filament\Resources\Ziyarah\ZiyarahLocationResource;

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
