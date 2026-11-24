<?php

namespace Modules\Transport\Filament\Resources\BoatBookings\Pages;

use Filament\Resources\Pages\ManageRecords;
use Modules\Transport\Filament\Resources\BoatBookings\BoatBookingResource;

class ManageBoatBookings extends ManageRecords
{
    protected static string $resource = BoatBookingResource::class;
}
