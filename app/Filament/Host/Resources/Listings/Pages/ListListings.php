<?php

namespace App\Filament\Host\Resources\Listings\Pages;

use App\Filament\Host\Resources\Listings\ListingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a listing')];
    }
}
