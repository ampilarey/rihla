<?php

namespace App\Filament\Host\Resources\Listings\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Host\Resources\Listings\ListingResource;
use App\Models\Property;
use Filament\Resources\Pages\CreateRecord;

/**
 * A host's new listing starts as a draft — the model's own default — and
 * belongs to the tenant, which Filament attaches through `partner`.
 */
class CreateListing extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = ListingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::withoutEmptyLocales($data, (new Property)->translatable);
    }
}
