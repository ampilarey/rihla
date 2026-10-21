<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Bookings\RelationManagers\LinesRelationManager;
use App\Filament\Resources\Bookings\RelationManagers\ScheduleRelationManager;
use App\Filament\Resources\Bookings\Schemas\BookingForm;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What the checkout produced, for the people who have to act on it.
 *
 * Until this screen existed the public booking flow wrote rows nobody at
 * Rihla could see: a customer got a reference and a WhatsApp message, and
 * finding out what they had actually booked meant a database client.
 *
 * **The create page is the desk** (App\Services\Booking\BookingDesk): a
 * walk-in or a phone call, through the same allocator and the same prices
 * as the checkout, in one transaction. Filament never inserts the row
 * itself — a form that did would bypass the row lock, and the departure
 * would oversell the first time two people used it at once.
 *
 * **No delete action.** A booking is a financial record; cancelling is a
 * status with a row saying who and why. `booking.delete` does not exist as
 * a permission, so the inherited policy method denies everybody.
 */
class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Bookings';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return BookingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingsTable::configure($table);
    }

    /** Bookings needing attention — held, waiting for somebody to act. */
    public static function getNavigationBadge(): ?string
    {
        $held = Booking::where('status', Booking::HELD)->count();

        return $held > 0 ? (string) $held : null;
    }

    /** What it comes to, line by line, and when the parts were expected. */
    public static function getRelations(): array
    {
        return [LinesRelationManager::class, ScheduleRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'view' => ViewBooking::route('/{record}'),
            'edit' => EditBooking::route('/{record}/edit'),
        ];
    }
}
