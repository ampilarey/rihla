<?php

namespace Modules\Content\Filament\Resources\GuideSteps\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\GuideSteps\GuideStepResource;

class ListGuideSteps extends ListRecords
{
    protected static string $resource = GuideStepResource::class;

    protected static ?string $title = 'Umrah guide';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a step')];
    }
}
