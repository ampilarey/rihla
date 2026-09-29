<?php

namespace App\Filament\Host\Resources\Bookings\Pages;

use App\Filament\Host\Resources\Bookings\BookingResource;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;
}
