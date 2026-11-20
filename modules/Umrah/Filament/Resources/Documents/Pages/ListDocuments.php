<?php

namespace Modules\Umrah\Filament\Resources\Documents\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Documents\DocumentResource;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a document')];
    }
}
