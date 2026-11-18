<?php

namespace App\Filament\Resources\LedgerCategories;

use App\Filament\Resources\LedgerCategories\Pages\ListLedgerCategories;
use App\Models\LedgerCategory;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * What ledger entries are for (companies plan, Phase 3f). Started from the
 * defaults; added to and switched off, never deleted, because entries name
 * them.
 */
class LedgerCategoryResource extends Resource
{
    protected static ?string $model = LedgerCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Ledger categories';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ledger.manage') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('kind')->options([LedgerCategory::INCOME => 'Income', LedgerCategory::EXPENSE => 'Spending'])->required()->native(false)->disabledOn('edit'),
            TextInput::make('name')->required()->maxLength(80)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state, '_')) : null),
            TextInput::make('slug')->required()->maxLength(40)->alphaDash()->disabledOn('edit')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('company_id', app(CompanyContext::class)->id())),
            TextInput::make('sort_order')->label('Order')->integer()->minValue(0)->default(0),
            Toggle::make('is_active')->label('In use')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                LedgerCategory::ensureDefaults();

                return $query;
            })
            ->columns([
                TextColumn::make('name')->description(fn (LedgerCategory $record): string => ($record->kind === LedgerCategory::INCOME ? 'Income' : 'Spending').($record->is_active ? '' : ' · not in use')),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLedgerCategories::route('/')];
    }
}
