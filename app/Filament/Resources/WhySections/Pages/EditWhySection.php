<?php

namespace App\Filament\Resources\WhySections\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\WhySections\WhySectionResource;
use App\Models\WhySection;
use Filament\Resources\Pages\EditRecord;

/**
 * No delete action, as the Blade screen had none: removing the one section
 * would take the block off the homepage, and switching it off is what
 * "Showing on the homepage" is for.
 */
class EditWhySection extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = WhySectionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $section = $this->section();

        $translations = [];

        foreach ($section->translatable as $attribute) {
            $translations[$attribute] = $section->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->section()->translatable);
    }

    private function section(): WhySection
    {
        /** @var WhySection */
        return $this->getRecord();
    }
}
