<?php

namespace Modules\Umrah\Filament\Resources\Announcements\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Announcements\AnnouncementResource;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Write an announcement')];
    }
}
