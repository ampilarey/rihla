<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Only ever reached for a draft — the list hides the edit action once a
 * quotation has been sent, because a sent one is superseded rather than
 * edited so the record keeps saying what the customer was told.
 */
class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
