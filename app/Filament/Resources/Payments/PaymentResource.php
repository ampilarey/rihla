<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use App\Services\Payments\Ledger;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Money received against bookings — §5.3.
 *
 * List only. A payment is opened from a booking and moved by actions that
 * record who decided what and why; a status dropdown would let somebody mark
 * money as received without leaving a trace of having done it, and "who said
 * this had arrived" is the question a disputed payment turns on.
 *
 * Nothing here writes the booking's paid total. That is
 * {@see Ledger}, under a row lock.
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payments';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    /**
     * How many claims are sitting unchecked.
     *
     * Work somebody has to do, not work that exists: a customer who has said
     * they paid and is waiting to hear is the queue that matters.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Payment::awaitingReview()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }
}
