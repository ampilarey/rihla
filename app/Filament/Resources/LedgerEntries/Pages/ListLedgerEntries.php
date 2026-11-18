<?php

namespace App\Filament\Resources\LedgerEntries\Pages;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Support\DepartureOptions;
use App\Models\Account;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Services\Finance\Cashbox;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->recordAction(LedgerEntry::INCOME, 'Record income', 'heroicon-o-arrow-down-tray', 'success'),
            $this->recordAction(LedgerEntry::EXPENSE, 'Record spending', 'heroicon-o-arrow-up-tray', 'danger'),
            $this->transferAction(),
        ];
    }

    private function recordAction(string $kind, string $label, string $icon, string $color): Action
    {
        return Action::make($kind)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (): bool => auth()->user()?->can('ledger.manage') === true)
            ->schema([
                Select::make('account_id')->label('Account')->required()->native(false)->live()->options(fn (): array => self::accounts()),
                TextInput::make('amount')->required()->integer()->minValue(1)
                    ->prefix(fn (Get $get): string => (string) (Account::query()->find($get('account_id'))->currency ?? '')),
                DatePicker::make('occurred_on')->label('On')->required()->native(false)->default(now())->maxDate(now()),
                Select::make('category_id')->label('For')->required()->native(false)->options(fn (): array => LedgerCategory::options($kind)),
                TextInput::make('description')->required()->maxLength(255),
                TextInput::make('counterparty')->label($kind === LedgerEntry::INCOME ? 'From' : 'Paid to')->maxLength(120),
                TextInput::make('reference')->label('Receipt or reference')->maxLength(80),
                Select::make('departure_id')->label('Departure, if it was for one')->native(false)->searchable()->options(fn (): array => DepartureOptions::all(recentOnly: true)),
            ])
            ->action(function (array $data) use ($kind): void {
                $account = Account::query()->findOrFail((int) $data['account_id']);

                try {
                    app(Cashbox::class)->record(
                        $account,
                        $kind,
                        Money::ofMajor((int) $data['amount'], $account->currency),
                        Carbon::parse($data['occurred_on']),
                        (string) $data['description'],
                        [
                            'category_id' => (int) $data['category_id'],
                            'departure_id' => filled($data['departure_id'] ?? null) ? (int) $data['departure_id'] : null,
                            'counterparty' => $data['counterparty'] ?: null,
                            'reference' => $data['reference'] ?: null,
                        ],
                    );
                } catch (DeskRefusal $e) {
                    Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Recorded')->send();
            });
    }

    private function transferAction(): Action
    {
        return Action::make('transfer')
            ->label('Transfer')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('ledger.manage') === true)
            ->modalDescription('Between two of the company\'s own accounts — the bank to the cash box, rufiyaa to a leader\'s riyal float. Not income or spending.')
            ->schema([
                Select::make('from')->label('From')->required()->native(false)->live()->options(fn (): array => self::accounts()),
                TextInput::make('out')->label('Amount out')->required()->integer()->minValue(1)
                    ->prefix(fn (Get $get): string => (string) (Account::query()->find($get('from'))->currency ?? '')),
                Select::make('to')->label('To')->required()->native(false)->live()->different('from')->options(fn (): array => self::accounts()),
                TextInput::make('in')->label('Amount in')->integer()->minValue(1)
                    ->prefix(fn (Get $get): string => (string) (Account::query()->find($get('to'))->currency ?? ''))
                    ->helperText('Only when the two accounts hold different currencies. Blank means the same amount.'),
                DatePicker::make('occurred_on')->label('On')->required()->native(false)->default(now())->maxDate(now()),
                TextInput::make('description')->required()->maxLength(255)->placeholder('Float for the Makkah week'),
            ])
            ->action(function (array $data): void {
                $from = Account::query()->findOrFail((int) $data['from']);
                $to = Account::query()->findOrFail((int) $data['to']);

                try {
                    app(Cashbox::class)->transfer(
                        $from,
                        $to,
                        Money::ofMajor((int) $data['out'], $from->currency),
                        Money::ofMajor((int) (filled($data['in'] ?? null) ? $data['in'] : $data['out']), $to->currency),
                        Carbon::parse($data['occurred_on']),
                        (string) $data['description'],
                    );
                } catch (DeskRefusal $e) {
                    Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Transferred')->send();
            });
    }

    /** @return array<int, string> */
    private static function accounts(): array
    {
        return Account::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->mapWithKeys(fn (Account $account): array => [$account->getKey() => $account->name.' ('.$account->currency.')'])
            ->all();
    }
}
