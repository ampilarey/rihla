<?php

namespace Modules\Transport\Filament\Resources\Vessels;

use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Transport\Filament\Operators;
use Modules\Transport\Filament\Resources\Vessels\Pages\ManageVessels;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Support\OperatorContext;
use UnitEnum;

/** Operators' boats — docs/TRANSPORT_PLAN.md, T1. */
class VesselResource extends Resource
{
    protected static ?string $model = Vessel::class;

    /**
     * Shared with the operator panel (T5), which scopes by OperatorContext
     * below rather than by Filament's tenant relation: a sailing or a
     * booking reaches its operator through its route.
     */
    protected static bool $isScopedToTenant = false;

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $id = OperatorContext::id();

        if ($id !== null) {
            $query->where('partner_id', $id);
        } elseif (OperatorContext::inPanel()) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Boats';

    protected static ?string $modelLabel = 'boat';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 3;

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
        return $schema->columns(2)->components([
            Select::make('partner_id')->label('Operator')->options(fn (): array => Operators::options())->required()->searchable()
                // Inside an operator's panel it is always theirs (the model sets it).
                ->hidden(fn (): bool => OperatorContext::inPanel()),
            TextInput::make('name')->required()->maxLength(80),
            Select::make('kind')->options(Vessel::KINDS)->required()->default(Vessel::SPEEDBOAT),
            TextInput::make('seats')->integer()->required()->minValue(1)->maxValue(500),
            TextInput::make('cargo_kg')->label('Cargo capacity (kg)')->integer()->minValue(0),
            TextInput::make('registration_number')->maxLength(40),
            Toggle::make('is_active')->label('In service')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('operator.name')->label('Operator'),
                TextColumn::make('kind')->formatStateUsing(fn (string $state): string => Vessel::KINDS[$state] ?? $state),
                TextColumn::make('seats')->alignEnd(),
                IconColumn::make('is_active')->label('In service')->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No boats yet');
    }

    public static function getPages(): array
    {
        return ['index' => ManageVessels::route('/')];
    }
}
