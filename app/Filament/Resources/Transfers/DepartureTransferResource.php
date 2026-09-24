<?php

namespace App\Filament\Resources\Transfers;

use App\Filament\Resources\Transfers\Pages\ListDepartureTransfers;
use App\Filament\Resources\Transfers\Schemas\DepartureTransferForm;
use App\Filament\Resources\Transfers\Tables\DepartureTransfersTable;
use App\Models\DepartureTransfer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Coaches, cars and trains on the ground — §8.3.
 *
 * The meeting point is shown to the pilgrim in their portal; the provider
 * and the driver's number are for the office and the tour leader.
 */
class DepartureTransferResource extends Resource
{
    protected static ?string $model = DepartureTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Ground transport';

    protected static ?string $modelLabel = 'transfer';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return DepartureTransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartureTransfersTable::configure($table);
    }

    public static function getBreadcrumb(): string
    {
        return 'Ground transport';
    }

    public static function getPages(): array
    {
        return ['index' => ListDepartureTransfers::route('/')];
    }
}
