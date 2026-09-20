<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Filament\Resources\Tasks\CrmTaskResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCrmTasks extends ListRecords
{
    protected static string $resource = CrmTaskResource::class;

    protected static ?string $title = 'Follow-ups';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a follow-up')];
    }
}
