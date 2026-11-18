<?php

namespace App\Filament\Resources\LedgerEntries;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Filament\Support\DepartureOptions;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Services\Finance\Cashbox;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The ledger (companies plan, Phase 3f): every entry, newest first, voided
 * ones struck through and still there. Written only through the header
 * actions, which go through {@see Cashbox}; never edited or deleted here,
 * and the model refuses it anyway.
 */
class LedgerEntryResource extends Resource
{
    protected static ?string $model = LedgerEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Ledger';

    protected static ?string $modelLabel = 'ledger entry';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('books.view') === true || auth()->user()?->can('ledger.manage') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['account', 'category', 'departure.package'])->latest('occurred_on')->latest('id'))
            ->columns([
                TextColumn::make('occurred_on')->label('On')->date('j M Y'),

                TextColumn::make('description')
                    ->label('What')
                    ->wrap()
                    ->description(fn (LedgerEntry $record): string => implode(' · ', array_filter([
                        $record->category->name ?? $record->kindLabel(),
                        $record->account->name,
                        $record->counterparty,
                        $record->departure ? DepartureOptions::label($record->departure) : null,
                        $record->isVoided() ? 'VOID: '.$record->void_reason : null,
                    ]))),

                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->state(fn (LedgerEntry $record): string => ($record->direction === LedgerEntry::OUT ? '− ' : '+ ').$record->money()->format())
                    ->color(fn (LedgerEntry $record): string => $record->isVoided() ? 'gray' : ($record->direction === LedgerEntry::OUT ? 'danger' : 'success')),
            ])
            ->filters([
                SelectFilter::make('account_id')->label('Account')->options(fn (): array => Account::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                SelectFilter::make('kind')->options(LedgerEntry::KINDS),
                TernaryFilter::make('voided')
                    ->label('Voided')
                    ->placeholder('Live only')
                    ->trueLabel('Voided only')
                    ->falseLabel('Everything')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('voided_at'),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query->whereNull('voided_at'),
                    ),
            ])
            ->recordActions([
                Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (LedgerEntry $record): bool => ! $record->isVoided() && auth()->user()?->can('ledger.manage') === true)
                    ->modalDescription(fn (LedgerEntry $record): string => $record->group_key !== null
                        ? 'This is one side of a transfer; both sides are voided together. They stay on the record.'
                        : 'The entry stays on the record, struck through, with your reason and your name.')
                    ->schema([Textarea::make('reason')->required()->rows(2)->placeholder('Entered twice')])
                    ->action(function (LedgerEntry $record, array $data): void {
                        try {
                            app(Cashbox::class)->void($record, (string) $data['reason']);
                        } catch (DeskRefusal $e) {
                            Notification::make()->danger()->title('Not voided')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Voided')->send();
                    }),
            ])
            ->emptyStateHeading('Nothing in the ledger yet')
            ->emptyStateDescription('Record each account\'s opening balance as income, then income and spending that is not a booking\'s payment or a departure\'s cost.');
    }

    public static function getPages(): array
    {
        return ['index' => ListLedgerEntries::route('/')];
    }
}
