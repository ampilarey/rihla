<?php

namespace Modules\Umrah\Filament\Resources\Transfers\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Transfers\DepartureTransferResource;

class ListDepartureTransfers extends ListRecords
{
    protected static string $resource = DepartureTransferResource::class;

    protected static ?string $title = 'Ground transport';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a transfer')];
    }
}
