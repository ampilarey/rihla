<?php

namespace Modules\Umrah\Filament\Resources\ExcursionBookings;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Umrah\Filament\Resources\ExcursionBookings\Pages\ListExcursionBookings;
use Modules\Umrah\Filament\Resources\ExcursionBookings\Tables\ExcursionBookingsTable;
use Modules\Umrah\Models\ExcursionBooking;
use UnitEnum;

/**
 * Trip bookings (companies plan, Phase 3e): each family's places on an
 * additional trip, with what they owe and have paid in the trip's currency.
 * Made by signing people up on Additional trips, never here; paid from
 * here through the same gateways and the same Finance check as a booking.
 */
class ExcursionBookingResource extends Resource
{
    protected static ?string $model = ExcursionBooking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Trip bookings';

    protected static ?string $modelLabel = 'trip booking';

    protected static ?string $pluralModelLabel = 'trip bookings';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ExcursionBookingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListExcursionBookings::route('/')];
    }
}
