<?php

namespace App\Filament\Resources\Notices\Pages;

use App\Filament\Resources\Notices\NoticeResource;
use Filament\Resources\Pages\ListRecords;

class ListNotices extends ListRecords
{
    protected static string $resource = NoticeResource::class;

    protected static ?string $title = 'Chasing list';

    /**
     * No create action. A notice is raised from a record that already
     * exists, by `notices:sweep`, and is never typed.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
