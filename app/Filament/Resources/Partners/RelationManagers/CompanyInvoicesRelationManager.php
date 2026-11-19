<?php

namespace App\Filament\Resources\Partners\RelationManagers;

use App\Exceptions\DeskRefusal;
use App\Models\CompanyInvoice;
use App\Models\Partner;
use App\Services\Companies\CompanyBilling;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Rihla's invoices to an Umrah company (billing automation). Issued by the
 * daily run or "Issue invoices now"; never typed in by hand, so the amounts
 * are always the ones on the company's record. Here a payment is recorded
 * once the transfer has arrived, an invoice not owed is cancelled with a
 * reason, and the PDF is downloaded.
 */
class CompanyInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'companyInvoices';

    protected static ?string $title = 'Invoices from Rihla';

    protected static ?string $modelLabel = 'invoice';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Partner
            && $ownerRecord->is_umrah_company
            && ! $ownerRecord->is_rihla
            && auth()->user()?->can('partner.view') === true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $canAct = fn (CompanyInvoice $record): bool => $record->isOutstanding() && auth()->user()?->can('partner.update') === true;

        return $table
            ->columns([
                TextColumn::make('number')->label('Invoice')->searchable(),
                TextColumn::make('description')->label('For')->state(fn (CompanyInvoice $record): string => $record->description()),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()
                    ->state(fn (CompanyInvoice $record): string => $record->money()->format()),
                TextColumn::make('state')->label('')->badge()
                    ->state(fn (CompanyInvoice $record): string => $record->stateLabel())
                    ->color(fn (CompanyInvoice $record): string => match ($record->state()) {
                        CompanyInvoice::PAID => 'success',
                        CompanyInvoice::OVERDUE => 'danger',
                        CompanyInvoice::CANCELLED => 'gray',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->visible($canAct)
                    ->schema([
                        DatePicker::make('paid_on')->label('Arrived on')->required()->maxDate(now())->default(now()),
                        TextInput::make('reference')->label('Bank reference')->maxLength(100),
                    ])
                    ->action(function (CompanyInvoice $record, array $data): void {
                        $this->attempt(fn () => app(CompanyBilling::class)->recordPayment($record, auth()->user(), (string) $data['paid_on'], $data['reference'] ?? null), 'Payment recorded');
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible($canAct)
                    ->modalDescription('The invoice is kept, marked cancelled, and its month is not invoiced again.')
                    ->schema([Textarea::make('reason')->label('Why it is not owed')->required()->maxLength(500)->rows(2)])
                    ->action(function (CompanyInvoice $record, array $data): void {
                        $this->attempt(fn () => app(CompanyBilling::class)->cancel($record, auth()->user(), (string) $data['reason']), 'Invoice cancelled');
                    }),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (CompanyInvoice $record) {
                        $pdf = app(CompanyBilling::class)->pdf($record);

                        return response()->streamDownload(fn () => print ($pdf), $record->number.'.pdf', ['Content-Type' => 'application/pdf']);
                    }),
            ])
            ->emptyStateHeading('No invoices yet')
            ->emptyStateDescription('Set a registration fee or a monthly fee above. The daily run issues them, or use "Issue invoices now".');
    }

    private function attempt(callable $change, string $done): void
    {
        try {
            $change();
            Notification::make()->success()->title($done)->send();
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title($refusal->getMessage())->send();
        }
    }
}
