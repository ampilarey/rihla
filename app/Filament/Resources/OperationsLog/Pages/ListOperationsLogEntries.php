<?php

namespace App\Filament\Resources\OperationsLog\Pages;

use App\Filament\Resources\OperationsLog\OperationsLogEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperationsLogEntries extends ListRecords
{
    protected static string $resource = OperationsLogEntryResource::class;

    // The navigation says "Daily log"; without this the heading and the
    // breadcrumb said "Log Entries", so the same screen had two names
    // depending on where you looked at it.
    protected static ?string $title = 'Daily log';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Write up a day')];
    }
}
