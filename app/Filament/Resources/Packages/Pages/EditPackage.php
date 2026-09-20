<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = PackageResource::class;

    /**
     * `$package->title` is the translation for the staff member's own locale
     * — a string. Filling the form from that would edit one language and
     * discard the other on save. This replaces each translatable key with
     * the whole `{"en": …, "dv": …}` array, which is what the `title.en`
     * fields bind to.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $package = $this->package();

        $translations = [];

        foreach ($package->translatable as $attribute) {
            $translations[$attribute] = $package->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * An empty box is not a translation, and storing one stops the fallback.
     * See the trait.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->package()->translatable);
    }

    private function package(): Package
    {
        /** @var Package */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
