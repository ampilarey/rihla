<?php

namespace App\Filament\Resources\BusGroups;

use App\Filament\Resources\BusGroups\Pages\ListBusGroups;
use App\Filament\Resources\BusGroups\Schemas\BusGroupForm;
use App\Filament\Resources\BusGroups\Tables\BusGroupsTable;
use App\Models\BusGroup;
use App\Services\Operations\Buses;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Buses — Hijaz's bus groups, brought across.
 *
 * Its own screen, like Rooming: a job somebody sits down to do for one
 * departure, in one sitting. A bus is made, people are put on it, and
 * the bus list and the luggage tags are printed from here. Nothing
 * allocates; see {@see Buses} for what is checked
 * instead of decided.
 */
class BusGroupResource extends Resource
{
    protected static ?string $model = BusGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Buses';

    protected static ?string $modelLabel = 'bus';

    protected static ?string $pluralModelLabel = 'buses';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return BusGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BusGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListBusGroups::route('/')];
    }
}
