<?php

namespace App\Filament\Resources\Bookings\Pages\Concerns;

use App\Models\Booking;
use App\Models\Discount;
use App\Services\Booking\BookingDesk;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * The two things done to a booking by somebody who may not edit it.
 *
 * Finance reads a booking and never edits one, so the edit page is closed
 * to it; it opens the view page instead. Both pages offer the invoice and,
 * to whoever holds `booking.discount`, the discount — the same actions,
 * written once.
 */
trait HasInvoiceAndDiscountActions
{
    private function bookingRecord(): Booking
    {
        /** @var Booking */
        return $this->getRecord();
    }

    private function invoiceAction(): Action
    {
        return Action::make('invoice')
            ->label('Invoice')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->url(fn (): string => route('staff.invoice', ['booking' => $this->bookingRecord()]), shouldOpenInNewTab: true);
    }

    /**
     * Knock something off the price, with a reason on the record.
     *
     * A money decision, so it takes `booking.discount` — Finance's and the
     * Operations Manager's, not the person who took the booking. The desk
     * writes a negative line and the row behind it; the invoice prints the
     * line, and "Charges" on the page can take it back.
     */
    private function discountAction(): Action
    {
        return Action::make('discount')
            ->label('Discount')
            ->icon('heroicon-o-receipt-percent')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('booking.discount') === true
                && $this->bookingRecord()->isLive()
                && $this->bookingRecord()->total_minor > 0)
            ->modalHeading('Give a discount')
            ->modalDescription(fn (): string => 'Off the '.$this->bookingRecord()->total()->format()
                .' this booking comes to. It prints on the invoice as its own line, with your name on the record.')
            ->schema([
                Select::make('kind')
                    ->label('As')
                    ->options([Discount::FIXED => 'An amount off', Discount::PERCENT => 'A percentage off'])
                    ->default(Discount::FIXED)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('value')
                    ->label(fn (callable $get): string => $get('kind') === Discount::PERCENT ? 'Percent off' : 'Amount off')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->maxValue(fn (callable $get): int => $get('kind') === Discount::PERCENT ? 100 : $this->bookingRecord()->total()->major())
                    ->prefix(fn (callable $get): string => $get('kind') === Discount::PERCENT ? '%' : $this->bookingRecord()->currency),
                Textarea::make('reason')
                    ->label('Why')
                    ->required()
                    ->rows(2)
                    ->placeholder('Returning pilgrim / group of ten / agreed by the owner'),
            ])
            ->action(function (array $data): void {
                try {
                    $discount = app(BookingDesk::class)->discount(
                        $this->bookingRecord(),
                        (string) $data['kind'],
                        (string) $data['value'],
                        (string) $data['reason'],
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title('No discount given')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($discount->money()->format().' off')->send();

                $this->refreshFormData(['total_minor', 'balance']);
            });
    }
}
