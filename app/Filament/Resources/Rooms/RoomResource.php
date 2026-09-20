<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Tables\RoomsTable;
use App\Models\DepartureHotel;
use App\Models\Room;
use App\Support\Rooming;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Rooming — §8.2, "where operator time disappears".
 *
 * Its own screen rather than a third level under Packages → Departures →
 * Hotels, because this is a job somebody sits down to do: a rooming list
 * for one hotel, in one sitting, checked for the mistakes that turn into an
 * argument at a hotel desk.
 *
 * Nothing here allocates. See {@see Rooming} for why.
 */
class RoomResource extends Resource
{
    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Rooming';

    protected static ?string $modelLabel = 'room';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return RoomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomsTable::configure($table);
    }

    /**
     * How many hotel stays have something wrong with their rooming.
     *
     * Counted per hotel rather than per problem: "four hotels need
     * attention" is a morning's work, and "sixty-one problems" is a number
     * that makes somebody close the tab.
     *
     * Only upcoming departures — a rooming mistake on a trip that came home
     * last March is history, not work.
     */
    public static function getNavigationBadge(): ?string
    {
        $unsettled = DepartureHotel::query()
            ->whereHas('departure', fn ($query) => $query->where('date_start', '>=', now()->startOfDay()))
            ->with(['rooms.assignments.traveller', 'departure'])
            ->get()
            ->filter(fn (DepartureHotel $hotel): bool => ! Rooming::isSettled($hotel))
            ->count();

        return $unsettled > 0 ? (string) $unsettled : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListRooms::route('/')];
    }
}
