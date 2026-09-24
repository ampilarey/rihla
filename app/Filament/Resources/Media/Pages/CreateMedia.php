<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Support\MediaImage;
use Filament\Resources\Pages\CreateRecord;

class CreateMedia extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = MediaResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['thumb_path'] = MediaImage::thumbFor($data['file_path'] ?? null);

        return self::withoutEmptyLocales($data, (new Media)->translatable);
    }
}
