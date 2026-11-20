<?php

namespace Modules\Content\Filament\Resources\GuideSteps\Pages;

use App\Filament\Concerns\EditsTranslations;
use Filament\Resources\Pages\CreateRecord;
use Modules\Content\Filament\Resources\GuideSteps\GuideStepResource;
use Modules\Content\Models\GuideStep;

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
