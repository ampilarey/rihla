<?php

namespace App\Filament\Resources\HostStatements;

use App\Exceptions\PayoutRefused;
use App\Filament\Resources\HostStatements\Pages\ListHostStatements;
use App\Models\HostStatement;
use App\Services\Hosts\Payouts;
use App\Services\Hosts\Statements;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Hosts' monthly statements — §16.9, the staff side. Read and download;
 * issuing is the monthly command's, or the button on the list.
 */
class HostStatementResource extends Resource
{
    protected static ?string $model = HostStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Host statements';

    protected static UnitEnum|string|null $navigationGroup = 'Stays';

    protected static ?int $navigationSort = 6;

    /** Whoever runs hosts, and Finance, who pays them (§16 Phase 16). */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->can('partner.viewAny') === true || $user?->can('payout.viewAny') === true;
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
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('partner.name')->label('Host')->searchable(),
                TextColumn::make('period_start')->label('Month')->date('F Y')->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('gross_minor')->label('Gross')->state(fn (HostStatement $record): string => $record->money('gross_minor')->format()),
                TextColumn::make('commission_minor')->label('Commission')->state(fn (HostStatement $record): string => $record->money('commission_minor')->format()),
                TextColumn::make('rihla_holds_minor')->label('Rihla holds for them')->state(fn (HostStatement $record): string => $record->money('rihla_holds_minor')->format()),
                TextColumn::make('commission_outstanding_minor')->label('Commission to settle')->state(fn (HostStatement $record): string => $record->money('commission_outstanding_minor')->format()),
                TextColumn::make('paid_out')->label('Paid out')->state(fn (HostStatement $record): string => $record->paidOut()->format()),
                TextColumn::make('still_owed')->label('Still owed to them')->state(fn (HostStatement $record): string => $record->stillOwed()->format()),
            ])
            ->defaultSort('period_start', 'desc')
            ->filters([
                SelectFilter::make('partner')->relationship('partner', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                self::recordPayout(),
                Action::make('download')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (HostStatement $record): StreamedResponse {
                        $pdf = app(Statements::class)->pdf($record);

                        return response()->streamDownload(fn () => print ($pdf), $record->reference.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ]);
    }

    /**
     * Finance records a bank transfer it has already made — §16 Phase 16.
     * The modal shows where to send it, in full, to the one role that
     * sends it.
     */
    private static function recordPayout(): Action
    {
        return Action::make('recordPayout')
            ->label('Record payout')
            ->icon('heroicon-o-banknotes')
            ->authorize(fn (): bool => auth()->user()?->can('payout.create') === true)
            ->visible(fn (HostStatement $record): bool => $record->stillOwed()->minor > 0)
            ->modalDescription(function (HostStatement $record): string {
                $host = $record->partner;

                return $host !== null && $host->hasPayoutDetails()
                    ? sprintf('Pay into %s · %s · %s. Still owed: %s.', $host->payout_bank_name, $host->payout_account_name, $host->payout_account_number, $record->stillOwed()->format())
                    : 'This host has not given a bank account yet, so nothing can be recorded. Ask them to add it under Payout details in their panel.';
            })
            ->schema(fn (HostStatement $record): array => [
                TextInput::make('amount')
                    ->label('Amount sent ('.$record->currency.')')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required()
                    ->default(fn (): int => intdiv($record->stillOwed()->minor, 100)),
                DatePicker::make('paid_on')->label('Sent on')->required()->default(now())->maxDate(now()),
                TextInput::make('reference')->label('Bank reference')->required()->maxLength(120),
            ])
            ->action(function (HostStatement $record, array $data, Action $action): void {
                try {
                    app(Payouts::class)->record(
                        $record,
                        Money::ofMajor((int) $data['amount'], $record->currency),
                        CarbonImmutable::parse($data['paid_on']),
                        (string) $data['reference'],
                        auth()->user(),
                    );
                } catch (PayoutRefused $refusal) {
                    Notification::make()->danger()->title($refusal->getMessage())->send();
                    $action->halt();
                }

                Notification::make()->success()->title('Payout recorded')->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListHostStatements::route('/')];
    }
}
