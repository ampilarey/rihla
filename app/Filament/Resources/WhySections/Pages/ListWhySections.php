<?php

namespace App\Filament\Resources\WhySections\Pages;

use App\Filament\Resources\WhySections\WhySectionResource;
use App\Models\WhySection;
use Filament\Resources\Pages\ListRecords;

/**
 * Not a list — a door to the one section.
 *
 * There is one row, so a table of one with an edit button is a click that
 * does nothing useful. Straight to the section, created with English
 * defaults the first time; see {@see WhySection::singleton()} for why
 * those defaults are English only.
 */
class ListWhySections extends ListRecords
{
    protected static string $resource = WhySectionResource::class;

    public function mount(): void
    {
        $this->redirect(WhySectionResource::getUrl('edit', ['record' => WhySection::singleton()]));
    }
}
