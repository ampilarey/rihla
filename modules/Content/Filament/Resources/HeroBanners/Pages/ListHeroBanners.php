<?php

namespace Modules\Content\Filament\Resources\HeroBanners\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\HeroBanners\HeroBannerResource;

class ListHeroBanners extends ListRecords
{
    protected static string $resource = HeroBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
