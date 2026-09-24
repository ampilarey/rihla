<?php

namespace App\Filament\Resources\Flights;

use App\Filament\Resources\Flights\Pages\ListDepartureFlights;
use App\Filament\Resources\Flights\Schemas\DepartureFlightForm;
use App\Filament\Resources\Flights\Tables\DepartureFlightsTable;
use App\Models\DepartureFlight;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The flights each departure takes — §8.3.
 *
 * Read by the departure board (a leg with fewer seats than travellers
 * blocks), by the pilgrim's portal and the family page (times, never the
 * booking reference), and by the tour leader.
 */
class DepartureFlightResource extends Resource
{
    protected static ?string $model = DepartureFlight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $navigationLabel = 'Flights';

    protected static ?string $modelLabel = 'flight';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return DepartureFlightForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartureFlightsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListDepartureFlights::route('/')];
    }
}
