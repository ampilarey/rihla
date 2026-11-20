<?php

namespace Modules\Content\Filament\Resources\LearningPaths\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\LearningPaths\LearningPathResource;

class ListLearningPaths extends ListRecords
{
    protected static string $resource = LearningPathResource::class;

    protected static ?string $title = 'Learning paths';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a path')];
    }
}
