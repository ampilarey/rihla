<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Models\Payment;
use App\Services\Payments\Ledger;
use App\Services\Payments\SlipVault;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public const COLOURS = [
        Payment::PENDING => 'gray',
        Payment::AWAITING_REVIEW => 'warning',
        Payment::SUCCEEDED => 'success',
        Payment::FAILED => 'danger',
        Payment::CANCELLED => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Payment')
                    ->searchable()
                    ->copyable()
                    ->description(fn (Payment $record): ?string => $record->booking()?->reference),

                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->sortable()
                    ->state(fn (Payment $record): string => $record->money()->format())
                    // A refund reads differently at a glance, because it is
                    // a different event and a column of identical-looking
                    // figures hides that.
                    ->color(fn (Payment $record): string => $record->isRefund() ? 'danger' : 'gray')
                    ->description(fn (Payment $record): ?string => $record->isRefund()
                        ? 'Refund of '.($record->refundOf->reference ?? 'an earlier payment')
                        : null),

                TextColumn::make('method')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => (string) config("payments.methods.{$state}.label", $state)),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('payer_name')
                    ->label('Sent by')
                    ->placeholder('—')
                    ->searchable()
                    ->description(fn (Payment $record): ?string => $record->payer_reference),

                TextColumn::make('slip_original_filename')
                    ->label('Slip')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : 'attached')
                    ->color(fn (Payment $record): string => $record->hasSlip() ? 'success' : 'gray'),

                TextColumn::make('paid_at')
                    ->label('Paid')
                    ->date('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    // Their word until somebody has looked. Said out loud so
                    // a date on a screen is not mistaken for a fact.
                    ->description(fn (Payment $record): ?string => $record->status === Payment::AWAITING_REVIEW
                        ? 'What the customer says'
                        : null),

                TextColumn::make('reviewer.name')
                    ->label('Checked by')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(
                        Payment::STATUSES,
                        array_map(fn (string $s): string => ucfirst(str_replace('_', ' ', $s)), Payment::STATUSES),
                    ))
                    ->multiple(),

                SelectFilter::make('method')
                    ->options(array_combine(
                        Payment::METHODS,
                        array_map(fn (string $m): string => (string) config("payments.methods.{$m}.label", $m), Payment::METHODS),
                    )),
            ])
            // Grouped: six columns and four buttons do not fit a laptop
            // screen laid out flat.
            ->recordActions([
                ActionGroup::make([
                    self::receiptAction(),
                    self::slipAction(),
                    self::uploadSlipAction(),
                    self::reconcileAction(),
                    self::refuseAction(),
                    self::refundAction(),
                ]),
            ])
            ->emptyStateHeading('No payments recorded')
            ->emptyStateDescription('Payments are opened from a booking. Nothing here writes a booking\'s paid total directly — that is recomputed from these rows under a lock.');
    }

    /**
     * A receipt, for money that actually arrived.
     *
     * Only on a succeeded payment: a receipt for a claim nobody has checked
     * is a document telling a customer their money was received before
     * anybody looked at it.
     */
    private static function receiptAction(): Action
    {
        return Action::make('receipt')
            ->label(fn (Payment $record): string => $record->isRefund() ? 'Refund note' : 'Receipt')
            ->icon('heroicon-o-document-arrow-down')
            ->visible(fn (Payment $record): bool => $record->status === Payment::SUCCEEDED
                && auth()->user()?->can('payment.view') === true)
            ->url(fn (Payment $record): string => route('staff.receipt', ['payment' => $record]), shouldOpenInNewTab: true);
    }

    /**
     * A fresh signed URL each time the row is drawn, valid for minutes.
     *
     * The link is the only thing between a bank slip and whoever ends up
     * holding the URL.
     */
    private static function slipAction(): Action
    {
        return Action::make('slip')
            ->label('Open the slip')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (Payment $record): bool => $record->hasSlip()
                && auth()->user()?->can('payment.download') === true)
            ->url(fn (Payment $record): string => app(SlipVault::class)->downloadUrl($record), shouldOpenInNewTab: true);
    }

    private static function uploadSlipAction(): Action
    {
        return Action::make('uploadSlip')
            ->label(fn (Payment $record): string => $record->hasSlip() ? 'Replace the slip' : 'Attach a slip')
            ->icon('heroicon-o-paper-clip')
            ->visible(fn (Payment $record): bool => ! $record->isSettled()
                && auth()->user()?->can('payment.create') === true)
            ->schema([
                FileUpload::make('slip')
                    ->label('Transfer slip')
                    ->disk(config('payments.slips.disk'))
                    ->visibility('private')
                    ->required()
                    ->acceptedFileTypes(config('payments.slips.mime_types'))
                    ->maxSize((int) config('payments.slips.max_kilobytes'))
                    // Handled by the vault, not by Filament's own storage:
                    // the vault checksums it, keeps the superseded file and
                    // records the replacement.
                    ->storeFiles(false)
                    ->helperText('The old one is kept. Nothing in here is ever overwritten.'),
            ])
            ->action(function (Payment $record, array $data): void {
                app(SlipVault::class)->attach($record, $data['slip']);

                Notification::make()->success()->title('Slip attached')->send();
            });
    }

    /** The finance decision, and the only thing that moves a booking's total. */
    private static function reconcileAction(): Action
    {
        return Action::make('reconcile')
            ->label('The money is in')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Payment $record): bool => ! $record->isSettled()
                && auth()->user()?->can('payment.reconcile') === true)
            ->requiresConfirmation()
            ->modalDescription('This adds the amount to the booking\'s paid total. Only do it once the money is actually in the account — this is the decision, and your name goes on it.')
            ->schema([
                Textarea::make('note')
                    ->label('Note for the record')
                    ->rows(2)
                    ->placeholder('Matched against the BML statement for 12 March'),
            ])
            ->action(function (Payment $record, array $data): void {
                app(Ledger::class)->reconcile($record, $data['note'] ?: null);

                Notification::make()->success()->title('Recorded as received')->send();
            });
    }

    private static function refuseAction(): Action
    {
        return Action::make('refuse')
            ->label('It is not there')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Payment $record): bool => ! $record->isSettled()
                && auth()->user()?->can('payment.reconcile') === true)
            ->schema([
                Textarea::make('reason')
                    ->label('What is wrong')
                    ->required()
                    ->rows(2)
                    // A specific reason is the one the customer can act on.
                    ->helperText('The customer will be told this. "The slip is for MVR 2,850, the booking is MVR 28,500" is useful; "rejected" is not.'),
            ])
            ->action(function (Payment $record, array $data): void {
                app(Ledger::class)->refuse($record, $data['reason']);

                Notification::make()->warning()->title('Recorded as not received')->send();
            });
    }

    /** A new negative row. The original keeps its date, reference and slip. */
    private static function refundAction(): Action
    {
        return Action::make('refund')
            ->label('Refund')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (Payment $record): bool => $record->status === Payment::SUCCEEDED
                && ! $record->isRefund()
                && auth()->user()?->can('payment.refund') === true)
            ->schema([
                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->prefix(fn (Payment $record): string => $record->currency)
                    ->helperText('Whole rufiyaa. Leave blank to refund the whole payment.'),

                Textarea::make('reason')->label('Why')->required()->rows(2),
            ])
            ->modalDescription('The original payment is kept exactly as it is — its date, its reference and its slip are the record of what was actually received. This adds a negative row against it.')
            ->action(function (Payment $record, array $data): void {
                $refund = app(Ledger::class)->refund(
                    $record,
                    filled($data['amount'] ?? null)
                        ? Money::ofMajor((int) $data['amount'], $record->currency)
                        : null,
                    $data['reason'],
                );

                Notification::make()
                    ->success()
                    ->title('Refund recorded: '.$refund->money()->format())
                    ->send();
            });
    }
}
