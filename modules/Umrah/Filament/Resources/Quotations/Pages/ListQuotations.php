<?php

namespace Modules\Umrah\Filament\Resources\Quotations\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Quotations\QuotationResource;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected static ?string $title = 'Quotations';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Write a quotation')];
    }
}
