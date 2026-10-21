<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\Concerns\HasInvoiceAndDiscountActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * A booking, read — for the people who may see one and not change it.
 *
 * Finance reconciles payments and gives discounts; Pilgrim Support answers
 * the phone. Neither may edit a booking, and Filament closes the edit page
 * to anybody who may not, so until this page existed those roles could
 * list bookings and open none. Same screen as the edit page, with its
 * fields read-only and the actions that are theirs: the invoice, and for
 * `booking.discount`, the discount. "Charges" and "Instalments" below are
 * the same relation managers; what each may do there is checked there.
 */
class ViewBooking extends ViewRecord
{
    use HasInvoiceAndDiscountActions;

    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->invoiceAction(),
            $this->discountAction(),
            EditAction::make()->label('Edit'),
        ];
    }
}
