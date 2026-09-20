<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * No create action: bookings come from the checkout, which takes the seats
 * under a row lock. See BookingResource.
 */
class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    /**
     * Tabs in the order somebody working through the day would want them:
     * what needs acting on first, then what is settled.
     */
    public function getTabs(): array
    {
        return [
            'needs_action' => Tab::make('Needs action')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [Booking::HELD, Booking::DRAFT]))
                ->badge(Booking::whereIn('status', [Booking::HELD, Booking::DRAFT])->count()),

            'confirmed' => Tab::make('Confirmed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Booking::CONFIRMED)),

            'all' => Tab::make('All'),
        ];
    }
}
