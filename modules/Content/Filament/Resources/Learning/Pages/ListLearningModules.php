<?php

namespace Modules\Content\Filament\Resources\Learning\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\Learning\LearningModuleResource;

class ListLearningModules extends ListRecords
{
    protected static string $resource = LearningModuleResource::class;

    protected static ?string $title = 'Learning modules';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start a module')];
    }
}
