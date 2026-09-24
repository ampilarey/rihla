<?php

namespace App\Filament\Resources\GuideSteps\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\GuideSteps\GuideStepResource;
use App\Models\GuideStep;
use Filament\Resources\Pages\CreateRecord;

class CreateGuideStep extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = GuideStepResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::withoutEmptyLocales($data, (new GuideStep)->translatable);
    }
}
