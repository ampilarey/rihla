<?php

namespace App\Filament\Resources\GuideSteps\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\GuideSteps\GuideStepResource;
use App\Models\GuideStep;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGuideStep extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = GuideStepResource::class;

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
        $step = $this->step();

        $translations = [];

        foreach ($step->translatable as $attribute) {
            $translations[$attribute] = $step->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->step()->translatable);
    }

    private function step(): GuideStep
    {
        /** @var GuideStep */
        return $this->getRecord();
    }
}
