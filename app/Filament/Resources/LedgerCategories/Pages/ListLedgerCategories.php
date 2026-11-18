<?php

namespace App\Filament\Resources\LedgerCategories\Pages;

use App\Filament\Resources\LedgerCategories\LedgerCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLedgerCategories extends ListRecords
{
    protected static string $resource = LedgerCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a category')];
    }
}
