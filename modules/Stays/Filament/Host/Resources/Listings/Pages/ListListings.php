<?php

namespace Modules\Stays\Filament\Host\Resources\Listings\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Host\Resources\Listings\ListingResource;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a listing')];
    }
}
