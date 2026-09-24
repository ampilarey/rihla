<?php

namespace App\Filament\Resources\Media\Pages;

use App\Filament\Resources\Media\MediaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected static ?string $title = 'Gallery';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add a photo or video')];
    }
}
