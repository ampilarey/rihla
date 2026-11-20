<?php

namespace Modules\Umrah\Filament\Resources\Quotations\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Umrah\Filament\Resources\Quotations\QuotationResource;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    protected static ?string $title = 'Write a quotation';
}
