<?php

namespace Modules\Travel\Filament\Resources\VisaProducts\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Modules\Travel\Filament\Resources\VisaProducts\VisaProductResource;

class ManageVisaProducts extends ManageRecords
{
    protected static string $resource = VisaProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a visa')
                ->mutateDataUsing(fn (array $data): array => VisaProductResource::fromForm($data)),
        ];
    }
}
