<?php

namespace Modules\Content\Filament\Resources\Learning\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Content\Filament\Resources\Learning\LearningModuleResource;

/** Where the quiz and the sources live. No delete: a withdrawn module keeps its reason. */
class EditLearningModule extends EditRecord
{
    protected static string $resource = LearningModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
