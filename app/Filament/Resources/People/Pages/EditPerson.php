<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\People\PersonResource;
use App\Models\Person;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPerson extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = PersonResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $person = $this->person();

        $translations = [];

        foreach ($person->translatable as $attribute) {
            $translations[$attribute] = $person->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->person()->translatable);
    }

    private function person(): Person
    {
        /** @var Person */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
