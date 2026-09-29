<?php

namespace App\Filament\Resources\Properties\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Properties\PropertyResource;
use App\Models\Property;
use Filament\Resources\Pages\CreateRecord;

class CreateProperty extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = PropertyResource::class;

    /**
     * The form submits every locale tab, so a property written only in
     * English arrives carrying `ar => ''` and `dv => ''`. Stored, those stop
     * the fallback: hasTranslation() answers true for an empty value, and
     * the Arabic page then renders a blank name instead of the English one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::withoutEmptyLocales($data, (new Property)->translatable);
    }

    /**
     * Staff are the approvers, so a listing they write is approved as it is
     * written — as every property before §16 was. A host's listing (Phase
     * 14) starts as a draft and waits for one of them.
     */
    protected function afterCreate(): void
    {
        /** @var Property $property */
        $property = $this->record;

        $property->forceFill([
            'approval' => Property::APPROVED,
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ])->save();
    }
}
