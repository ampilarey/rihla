<?php

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Umrah\Models\DepartureExcursion;
use UnitEnum;

/**
 * Accounts (companies plan, Phase 3f): where the company's money sits, and
 * what the ledger says is in each. Read by whoever reads the books; kept by
 * `ledger.manage`. Never deleted — switched off — because the ledger's
 * entries point at it.
 */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Accounts';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('books.view') === true || auth()->user()?->can('ledger.manage') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('ledger.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(80)->placeholder('BML current account'),
            Select::make('kind')->options(Account::KINDS)->required()->native(false)->default(Account::BANK),
            Select::make('currency')
                ->options(array_combine(DepartureExcursion::CURRENCIES, DepartureExcursion::CURRENCIES))
                ->required()
                ->native(false)
                ->default('MVR')
                ->disabledOn('edit')
                ->helperText('Fixed once saved: every entry in it is in this currency.'),
            TextInput::make('bank')->maxLength(80),
            TextInput::make('account_name')->label('Name on the account')->maxLength(120),
            TextInput::make('account_number')->label('Account number')->maxLength(40)->helperText('Kept encrypted; lists show the last four digits.'),
            Toggle::make('is_active')->label('In use')->default(true),
            TextInput::make('sort_order')->label('Order')->integer()->minValue(0)->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->description(fn (Account $record): string => $record->kindLabel().($record->maskedNumber() ? ' · '.$record->maskedNumber() : '').($record->is_active ? '' : ' · not in use')),
                TextColumn::make('balance')
                    ->label('Balance')
                    ->alignEnd()
                    ->state(fn (Account $record): string => $record->balance()->format()),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('No accounts yet')
            ->emptyStateDescription('Add the bank account, the office cash box and each tour leader\'s float, then record an opening balance for each on the Ledger.');
    }

    public static function getPages(): array
    {
        return ['index' => ListAccounts::route('/')];
    }
}
