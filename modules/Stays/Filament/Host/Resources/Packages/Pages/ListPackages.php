<?php

namespace Modules\Stays\Filament\Host\Resources\Packages\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Stays\Filament\Host\Resources\Packages\PackageResource;

class ListPackages extends ListRecords
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New package')];
    }
}
