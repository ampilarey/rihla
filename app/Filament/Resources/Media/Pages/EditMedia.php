<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Media\MediaResource;
use App\Models\Media;
use App\Support\MediaImage;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMedia extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $medium = $this->medium();

        $translations = [];

        foreach ($medium->translatable as $attribute) {
            $translations[$attribute] = $medium->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // A new photograph brings its own thumbnail; the old one is removed
        // with it by the model.
        if (array_key_exists('file_path', $data) && $data['file_path'] !== $this->medium()->file_path) {
            $data['thumb_path'] = MediaImage::thumbFor($data['file_path']);
        }

        return self::withoutEmptyLocales($data, $this->medium()->translatable);
    }

    private function medium(): Media
    {
        /** @var Media */
        return $this->getRecord();
    }
}
