<?php

namespace App\Filament\Resources\Learning\Pages;

use App\Filament\Resources\Learning\LearningModuleResource;
use Filament\Resources\Pages\EditRecord;

/** Where the quiz and the sources live. No delete: a withdrawn module keeps its reason. */
class EditLearningModule extends EditRecord
{
    protected static string $resource = LearningModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
