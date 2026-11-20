<?php

namespace Modules\Umrah\Filament\Resources\RollCalls\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\RollCalls\RollCallResource;

class ListRollCalls extends ListRecords
{
    protected static string $resource = RollCallResource::class;

    // The navigation says "Attendance"; without this the heading and the
    // breadcrumb said "Head Counts", so the same screen had two names
    // depending on where you looked at it.
    protected static ?string $title = 'Attendance';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start a head count')];
    }
}
