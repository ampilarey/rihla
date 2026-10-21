<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * "New booking" is the desk — see BookingResource — and is offered only to
 * somebody holding `booking.create`; everything else here comes from the
 * checkout.
 */
class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New booking')->icon('heroicon-o-plus')];
    }

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
