<?php

namespace Modules\Content\Filament\Resources\HeroBanners\Pages;

use App\Filament\Concerns\EditsTranslations;
use Filament\Resources\Pages\CreateRecord;
use Modules\Content\Filament\Resources\HeroBanners\HeroBannerResource;
use Modules\Content\Models\HeroBanner;

class CreateHeroBanner extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = HeroBannerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::withoutEmptyLocales($data, (new HeroBanner)->translatable);
    }
}
