<?php

namespace Modules\Umrah\Filament\Resources\ExcursionBookings\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Umrah\Filament\Resources\ExcursionBookings\ExcursionBookingResource;

class ListExcursionBookings extends ListRecords
{
    protected static string $resource = ExcursionBookingResource::class;
}
