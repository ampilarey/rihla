<?php

namespace Modules\Umrah\Filament\Resources\Waitlist\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\Waitlist\WaitlistEntryResource;

class ListWaitlistEntries extends ListRecords
{
    protected static string $resource = WaitlistEntryResource::class;
}
