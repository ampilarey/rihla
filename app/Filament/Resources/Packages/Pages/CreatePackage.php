<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Resources\Pages\CreateRecord;

class CreatePackage extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = PackageResource::class;

    /**
     * The form submits both locale tabs, so a package written only in
     * English arrives carrying `dv => ''` and `dv => []`. Stored, those make
     * hasTranslation() answer true and stop the fallback firing — the
     * Dhivehi page would show a blank title and an empty inclusions list
     * rather than the English.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->pruneEmptyTranslations($data, new Package);
    }
}
