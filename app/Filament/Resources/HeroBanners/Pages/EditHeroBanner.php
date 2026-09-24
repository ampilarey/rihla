<?php

namespace App\Filament\Resources\HeroBanners\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\HeroBanners\HeroBannerResource;
use App\Models\HeroBanner;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHeroBanner extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = HeroBannerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $banner = $this->banner();

        $translations = [];

        foreach ($banner->translatable as $attribute) {
            $translations[$attribute] = $banner->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->banner()->translatable);
    }

    private function banner(): HeroBanner
    {
        /** @var HeroBanner */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
