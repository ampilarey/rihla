<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\People\PersonResource;
use App\Models\Person;
use Filament\Resources\Pages\CreateRecord;

class CreatePerson extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = PersonResource::class;

    /**
     * Both locale tabs are always submitted, so a profile written only in
     * English arrives carrying `dv => ''`. Stored, hasTranslation() answers
     * true for that and the fallback never fires — the Dhivehi page would
     * show a blank biography rather than the English one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::withoutEmptyLocales($data, (new Person)->translatable);
    }
}
