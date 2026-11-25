<?php

namespace App\Filament\Actions;

use App\Models\Payment;
use App\Services\Payments\Gateways;
use App\Services\Payments\TakesPayments;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Record money for anything that takes payments (a boat booking, a cargo
 * request, a trip), in its own currency.
 * It goes through the same gateways as every other payment, so it is only
 * *recorded*: Finance checks it on the payments screen, and only then does
 * the paid total move (and with it the status).
 */
final class RecordPayment
{
    /** @param  callable(Model): bool  $visible */
    public static function action(callable $visible): Action
    {
        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (Model $record): bool => auth()->user()?->can('payment.create') === true && $visible($record))
            ->schema([
                Select::make('method')->label('How')->required()->native(false)
                    ->options([Payment::BANK_TRANSFER => 'Bank transfer', Payment::CASH => 'Cash at the office'])
                    ->default(Payment::BANK_TRANSFER),
                TextInput::make('amount')->label('Amount')->numeric()->integer()->minValue(1)->required()
                    ->prefix(fn (Model $record): string => $record instanceof TakesPayments ? $record->paymentCurrency() : 'MVR'),
                DatePicker::make('paid_at')->label('Arrived on')->required()->maxDate(now())->default(now()),
                TextInput::make('payer_name')->label('Sent by')->maxLength(120),
                TextInput::make('payer_reference')->label('Bank reference')->maxLength(100),
            ])
            ->action(function (Model $record, array $data): void {
                if (! $record instanceof TakesPayments) {
                    return;
                }

                $payment = app(Gateways::class)->for((string) $data['method'])->start(
                    $record,
                    Money::ofMajor((int) $data['amount'], $record->paymentCurrency()),
                    [
                        'paid_at' => $data['paid_at'] ?: null,
                        'payer_name' => $data['payer_name'] ?: null,
                        'payer_reference' => $data['payer_reference'] ?? null,
                    ],
                );

                Notification::make()->success()
                    ->title('Recorded '.$payment->money()->format())
                    ->body('Nothing has been added to the paid total yet. Finance checks it on the payments screen.')
                    ->send();
            });
    }
}
