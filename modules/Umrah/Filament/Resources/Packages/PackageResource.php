<?php

namespace Modules\Umrah\Filament\Resources\Packages;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Umrah\Filament\Resources\Packages\Pages\CreatePackage;
use Modules\Umrah\Filament\Resources\Packages\Pages\EditPackage;
use Modules\Umrah\Filament\Resources\Packages\Pages\ListPackages;
use Modules\Umrah\Filament\Resources\Packages\RelationManagers\DeparturesRelationManager;
use Modules\Umrah\Filament\Resources\Packages\Schemas\PackageForm;
use Modules\Umrah\Filament\Resources\Packages\Tables\PackagesTable;
use Modules\Umrah\Models\Package;
use UnitEnum;

/**
 * The product Rihla sells, separately from when it runs.
 *
 * Until now the only way to edit either was the `trips` screen in the Blade
 * admin, where the product and the date are the same record — so running a
 * package twice meant retyping it. See docs/adr/0001 and the packages
 * migration.
 */
class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'Packages';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    /** Hosts' packages waiting for Rihla to price them — §16 Phase 16. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Package::query()
            ->whereNotNull('partner_id')
            ->whereNotNull('submitted_at')
            ->where('is_published', false)
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function form(Schema $schema): Schema
    {
        return PackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PackagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [DeparturesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackages::route('/'),
            'create' => CreatePackage::route('/create'),
            'edit' => EditPackage::route('/{record}/edit'),
        ];
    }
}
