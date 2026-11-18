<?php

namespace App\Filament\Resources\FlightGroups;

use App\Filament\Resources\FlightGroups\Pages\ListFlightGroups;
use App\Filament\Resources\FlightGroups\Schemas\FlightGroupForm;
use App\Filament\Resources\FlightGroups\Tables\FlightGroupsTable;
use App\Models\FlightGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Flight groups — Hijaz's trip groups, brought across (companies plan,
 * Phase 3c). A departure too big for one aircraft flies in groups; each
 * group is the people on one flight out and one home, with its own buses.
 * Its own screen beside Buses, because it is the job done just before
 * them: groups first, then a bus or two for each.
 */
class FlightGroupResource extends Resource
{
    protected static ?string $model = FlightGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Flight groups';

    protected static ?string $modelLabel = 'flight group';

    protected static ?string $pluralModelLabel = 'flight groups';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return FlightGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FlightGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListFlightGroups::route('/')];
    }
}
