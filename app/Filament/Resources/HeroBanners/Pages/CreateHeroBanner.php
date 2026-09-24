<?php

namespace App\Filament\Resources\HeroBanners\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\HeroBanners\HeroBannerResource;
use App\Models\HeroBanner;
use Filament\Resources\Pages\CreateRecord;

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
