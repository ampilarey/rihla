<?php

namespace App\Filament\Resources\Learning\Pages;

use App\Filament\Resources\Learning\LearningModuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLearningModules extends ListRecords
{
    protected static string $resource = LearningModuleResource::class;

    protected static ?string $title = 'Learning modules';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start a module')];
    }
}
