<?php

namespace Modules\Umrah\Filament\Resources\Costs;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Modules\Umrah\Filament\Resources\Costs\Pages\ListDepartureCosts;
use Modules\Umrah\Filament\Resources\Costs\Schemas\DepartureCostForm;
use Modules\Umrah\Filament\Resources\Costs\Tables\DepartureCostsTable;
use Modules\Umrah\Models\DepartureCost;
use UnitEnum;

/**
 * What each departure costs to run — §8.4.
 *
 * This is also the "package cost builder" §8.4 asks for: a cost entered
 * while a departure is still selling is an estimate, and the same rows
 * become the actual figures as invoices arrive. A second screen for
 * planning would mean two sets of numbers that have to be kept in step,
 * and they would not be.
 */
class DepartureCostResource extends Resource
{
    protected static ?string $model = DepartureCost::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Journey costs';

    protected static ?string $modelLabel = 'cost';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return DepartureCostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartureCostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartureCosts::route('/'),
            'create' => Pages\CreateDepartureCost::route('/create'),
            'edit' => Pages\EditDepartureCost::route('/{record}/edit'),
        ];
    }
}
