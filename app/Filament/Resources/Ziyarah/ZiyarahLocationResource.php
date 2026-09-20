<?php

namespace App\Filament\Resources\Ziyarah;

use App\Filament\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Ziyarah\Pages\EditZiyarahLocation;
use App\Filament\Resources\Ziyarah\Pages\ListZiyarahLocations;
use App\Filament\Resources\Ziyarah\Schemas\ZiyarahLocationForm;
use App\Filament\Resources\Ziyarah\Tables\ZiyarahLocationsTable;
use App\Models\ZiyarahLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Ziyarah Guide — §7.2.
 *
 * The badge counts **locations waiting on a scholar**, for the same reason
 * the Knowledge Centre's does: a count of locations says somebody has been
 * writing, which is not news; a count stuck in review is a person who has
 * not been asked.
 */
class ZiyarahLocationResource extends Resource
{
    protected static ?string $model = ZiyarahLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Ziyarah Guide';

    protected static ?string $modelLabel = 'location';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ZiyarahLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ZiyarahLocationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MisconceptionsRelationManager::class,
            ReferencesRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = ZiyarahLocation::awaitingAScholar()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getBreadcrumb(): string
    {
        return 'Ziyarah Guide';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListZiyarahLocations::route('/'),
            'edit' => EditZiyarahLocation::route('/{record}/edit'),
        ];
    }
}
