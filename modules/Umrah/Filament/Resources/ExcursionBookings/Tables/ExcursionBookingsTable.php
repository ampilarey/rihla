<?php

namespace Modules\Umrah\Filament\Resources\ExcursionBookings\Tables;

use App\Exceptions\DeskRefusal;
use App\Models\Payment;
use App\Services\Operations\Excursions;
use App\Services\Payments\Gateways;
use App\Support\CompanyRequest;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Umrah\Models\DepartureExcursion;
use Modules\Umrah\Models\ExcursionBooking;
use Modules\Umrah\Models\ExcursionParticipant;

class ExcursionBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['excursion', 'customer', 'booking', 'participants.bookingTraveller.traveller'])
                ->latest('id'))
            ->columns([
                TextColumn::make('reference')
                    ->label('Trip booking')
                    ->searchable()
                    ->copyable()
                    ->description(fn (ExcursionBooking $record): string => $record->excursion->title.' · '.$record->customer->name),

                TextColumn::make('people')
                    ->label('Who')
                    ->state(fn (ExcursionBooking $record): array => $record->participants
                        ->map(fn (ExcursionParticipant $p): string => $p->bookingTraveller->traveller->full_name)
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(4)
                    ->visibleFrom('md'),

                TextColumn::make('total_minor')
                    ->label('Owed')
                    ->alignEnd()
                    ->state(fn (ExcursionBooking $record): string => $record->balance()->format())
                    ->description(fn (ExcursionBooking $record): string => 'of '.$record->total()->format()),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ExcursionBooking::PAID => 'success',
                        ExcursionBooking::CANCELLED => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('departure_excursion_id')
                    ->label('Trip')
                    ->options(fn (): array => DepartureExcursion::query()->with('departure.package')->latest('id')->limit(200)->get()
                        ->mapWithKeys(fn (DepartureExcursion $x): array => [$x->getKey() => $x->title.' · '.($x->departure->package->title ?? 'Departure')])
                        ->all()),

                SelectFilter::make('status')
                    ->options([ExcursionBooking::OPEN => 'Open', ExcursionBooking::PAID => 'Paid', ExcursionBooking::CANCELLED => 'Cancelled']),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::paymentAction(),
                    Action::make('invoice')
                        ->label('Invoice')
                        ->icon('heroicon-o-document-arrow-down')
                        ->color('gray')
                        ->visible(fn (): bool => auth()->user()?->can('booking.view') === true)
                        ->url(fn (ExcursionBooking $record): string => CompanyRequest::route('staff.excursion-invoice', ['excursionBooking' => $record]), shouldOpenInNewTab: true),
                    self::removeAction(),
                    self::cancelAction(),
                ]),
            ])
            ->emptyStateHeading('No trip bookings yet')
            ->emptyStateDescription('Sign people up for an additional trip and their family\'s places appear here, ready to invoice and pay.');
    }

    /**
     * Money for the trip, in its currency, through the same gateways as a
     * booking's — so it waits for Finance's check on the payments screen
     * before it counts, exactly as a booking's does.
     */
    private static function paymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Record a payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (ExcursionBooking $record): bool => auth()->user()?->can('payment.create') === true
                && $record->status !== ExcursionBooking::CANCELLED
                && $record->balance()->minor > 0)
            ->modalDescription(fn (ExcursionBooking $record): string => $record->balance()->format().' still owed.')
            ->schema([
                Select::make('method')
                    ->options([Payment::CASH => 'Cash', Payment::BANK_TRANSFER => 'Bank transfer'])
                    ->default(Payment::CASH)
                    ->required()
                    ->native(false),
                TextInput::make('amount')
                    ->label('Amount')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->prefix(fn (ExcursionBooking $record): string => $record->currency)
                    ->default(fn (ExcursionBooking $record): int => max(0, $record->balance()->major())),
                DatePicker::make('paid_at')->label('Paid on')->native(false)->default(now())->maxDate(now()),
                TextInput::make('payer_name')->label('Paid by')->maxLength(120),
                Textarea::make('notes')->label('Note')->rows(2),
            ])
            ->action(function (ExcursionBooking $record, array $data): void {
                $payment = app(Gateways::class)->for($data['method'])->start(
                    $record,
                    Money::ofMajor((int) $data['amount'], $record->currency),
                    [
                        'paid_at' => $data['paid_at'] ?: null,
                        'payer_name' => $data['payer_name'] ?: null,
                        'notes' => $data['notes'] ?: null,
                    ],
                );

                Notification::make()
                    ->success()
                    ->title('Recorded '.$payment->money()->format())
                    ->body('Nothing has been added to the paid total yet. Finance checks it on the payments screen.')
                    ->send();
            });
    }

    private static function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Take somebody off')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (ExcursionBooking $record): bool => auth()->user()?->can('booking.update') === true
                && $record->status !== ExcursionBooking::CANCELLED
                && $record->participants->isNotEmpty())
            ->schema([
                Select::make('id')
                    ->label('Who')
                    ->required()
                    ->native(false)
                    ->options(fn (ExcursionBooking $record): array => $record->participants
                        ->mapWithKeys(fn (ExcursionParticipant $p): array => [$p->getKey() => $p->bookingTraveller->traveller->full_name])
                        ->all()),
            ])
            ->action(function (ExcursionBooking $record, array $data): void {
                try {
                    app(Excursions::class)->remove($record->participants()->findOrFail((int) $data['id']));
                } catch (DeskRefusal $e) {
                    Notification::make()->danger()->title('Not taken off')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Taken off '.$record->excursion->title)->send();
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel these places')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (ExcursionBooking $record): bool => auth()->user()?->can('booking.update') === true && $record->status !== ExcursionBooking::CANCELLED)
            ->schema([Textarea::make('reason')->rows(2)])
            ->action(function (ExcursionBooking $record, array $data): void {
                try {
                    app(Excursions::class)->cancel($record, $data['reason'] ?: null);
                } catch (DeskRefusal $e) {
                    Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($record->reference.' cancelled')->send();
            });
    }
}
