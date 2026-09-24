<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Property;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProperty extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = PropertyResource::class;

    /**
     * `$property->name` is the translation for the staff member's own locale
     * — a string. Filling the form from that would edit one language and
     * discard the other two on save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $property = $this->property();

        $translations = [];

        foreach ($property->translatable as $attribute) {
            $translations[$attribute] = $property->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->property()->translatable);
    }

    private function property(): Property
    {
        /** @var Property */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
