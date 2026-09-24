<?php

namespace App\Filament\Resources\Transfers\Pages;

use App\Filament\Resources\Transfers\DepartureTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDepartureTransfers extends ListRecords
{
    protected static string $resource = DepartureTransferResource::class;

    protected static ?string $title = 'Ground transport';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a transfer')];
    }
}
