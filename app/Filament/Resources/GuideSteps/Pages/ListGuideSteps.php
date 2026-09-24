<?php

namespace App\Filament\Resources\GuideSteps\Pages;

use App\Filament\Resources\GuideSteps\GuideStepResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGuideSteps extends ListRecords
{
    protected static string $resource = GuideStepResource::class;

    protected static ?string $title = 'Umrah guide';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a step')];
    }
}
