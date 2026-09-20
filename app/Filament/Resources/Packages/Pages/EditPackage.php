<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Packages\PackageResource;
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
        return $this->expandTranslations($data);
    }

    /**
     * The other half. See CreatePackage: an empty box is not a translation,
     * and storing one stops the fallback.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pruneEmptyTranslations($data);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
