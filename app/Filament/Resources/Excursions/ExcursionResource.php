<?php

namespace App\Filament\Resources\Excursions;

use App\Filament\Resources\Excursions\Pages\ListExcursions;
use App\Filament\Resources\Excursions\Schemas\ExcursionForm;
use App\Filament\Resources\Excursions\Tables\ExcursionsTable;
use App\Models\DepartureExcursion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Additional trips — Hijaz's excursions, brought across (companies plan,
 * Phase 3e): Taif, Jeddah, a Ziyarah, each on one departure, priced in
 * riyal. People are signed up from here; each family's places become a
 * trip booking with its own payments and invoice ({@see ExcursionBookingResource}).
 */
class ExcursionResource extends Resource
{
    protected static ?string $model = DepartureExcursion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Additional trips';

    protected static ?string $modelLabel = 'additional trip';

    protected static ?string $pluralModelLabel = 'additional trips';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return ExcursionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExcursionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListExcursions::route('/')];
    }
}
