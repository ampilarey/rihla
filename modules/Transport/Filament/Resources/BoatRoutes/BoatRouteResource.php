<?php

namespace Modules\Transport\Filament\Resources\BoatRoutes;

use App\Filament\Support\IslandSelect;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Transport\Filament\Operators;
use Modules\Transport\Filament\Resources\BoatRoutes\Pages\ManageBoatRoutes;
use Modules\Transport\Models\BoatRoute;
use UnitEnum;

/** Operators' routes and fares — docs/TRANSPORT_PLAN.md, T1. */
class BoatRouteResource extends Resource
{
    protected static ?string $model = BoatRoute::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Routes';

    protected static ?string $modelLabel = 'route';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('transport.viewAny') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('transport.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('transport.manage') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        // Whole rufiyaa on the screen, laari in the column, converted here
        // and in Money only.
        $fare = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->numeric()->minValue(0)
            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : number_format($state / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): ?int => $state === null || $state === '' ? null : (int) round(((float) $state) * 100));

        return $schema->columns(2)->components([
            Select::make('partner_id')->label('Operator')->options(fn (): array => Operators::options())->required()->searchable(),
            TextInput::make('duration_minutes')->label('Crossing time (minutes)')->integer()->required()->minValue(1)->maxValue(1440),
            IslandSelect::make('origin_island_id')->label('From')->required(),
            IslandSelect::make('destination_island_id')->label('To')->required()->different('origin_island_id'),
            Select::make('currency')->options(['MVR' => 'MVR', 'USD' => 'USD'])->default('MVR')->required(),
            $fare('adult_fare_minor', 'Adult fare')->required(),
            $fare('child_fare_minor', 'Child fare')->helperText('Leave empty and a child pays the adult fare.'),
            Toggle::make('is_active')->label('Running')->default(true),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Route')->state(fn (BoatRoute $record): string => $record->label()),
                TextColumn::make('operator.name')->label('Operator'),
                TextColumn::make('duration_minutes')->label('Minutes')->alignEnd(),
                TextColumn::make('adult_fare_minor')->label('Adult fare')->alignEnd()
                    ->state(fn (BoatRoute $record): string => (string) Money::ofMinor($record->adult_fare_minor, $record->currency)),
                IconColumn::make('is_active')->label('Running')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No routes yet');
    }

    public static function getPages(): array
    {
        return ['index' => ManageBoatRoutes::route('/')];
    }
}
