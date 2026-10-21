<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Discount;
use App\Services\Booking\BookingDesk;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * What the booking comes to, line by line: a seat per traveller, the
 * extras and fees added since, and any discount as a negative line.
 *
 * Until this existed the lines were visible only on the invoice. Extras
 * and fees are added and removed here; a discount is given from the
 * booking's own actions (it is a money decision with its own permission)
 * and only taken back here.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Charges';

    /**
     * Gated on the booking's own permissions. A line has no life of its own,
     * so a five-verb policy for it would be five permissions nobody would
     * ever grant separately — and Filament denies without one.
     */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('booking.view') === true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->wrap()
                    ->description(fn (BookingLine $record): ?string => $record->quantity > 1
                        ? $record->quantity.' × '.Money::ofMinor($record->unit_amount_minor, $record->currency)->format()
                        : null),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === BookingLine::DISCOUNT ? 'success' : 'gray')
                    ->visibleFrom('sm'),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->alignEnd()
                    ->state(fn (BookingLine $record): string => $record->money()->format())
                    ->color(fn (BookingLine $record): ?string => $record->amount_minor < 0 ? 'success' : null),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                Action::make('addLine')
                    ->label('Add a charge')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->booking()->isLive())
                    ->authorize(fn (): bool => auth()->user()?->can('booking.update') === true)
                    ->modalHeading('Add a charge')
                    ->schema([
                        Select::make('type')
                            ->options([
                                BookingLine::EXTRA => 'Extra — something bought: an upgraded room, a ziyarah tour',
                                BookingLine::FEE => 'Fee — not part of the package: a visa fee, a late change',
                            ])
                            ->default(BookingLine::EXTRA)
                            ->required()
                            ->native(false),
                        TextInput::make('description')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Visa fee / Zamzam 5L / Wheelchair at the airport'),
                        TextInput::make('amount')
                            ->label('Each')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->prefix(fn (): string => $this->booking()->currency)
                            ->helperText('Whole units.'),
                        TextInput::make('quantity')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(1)
                            ->default(1),
                    ])
                    ->action(function (array $data): void {
                        try {
                            app(BookingDesk::class)->addLine(
                                $this->booking(),
                                (string) $data['type'],
                                (string) $data['description'],
                                (int) $data['amount'],
                                (int) $data['quantity'],
                            );
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->danger()->title('Not added')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Charge added')->send();
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (BookingLine $record): bool => $this->booking()->isLive()
                        && in_array($record->type, [BookingLine::EXTRA, BookingLine::FEE], true))
                    ->authorize(fn (): bool => auth()->user()?->can('booking.update') === true)
                    ->requiresConfirmation()
                    ->action(function (BookingLine $record): void {
                        app(BookingDesk::class)->removeLine($record);

                        Notification::make()->success()->title('Charge removed')->send();
                    }),

                Action::make('takeBack')
                    ->label('Take back')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (BookingLine $record): bool => $this->booking()->isLive() && $record->type === BookingLine::DISCOUNT)
                    ->authorize(fn (): bool => auth()->user()?->can('booking.discount') === true)
                    ->requiresConfirmation()
                    ->modalDescription('The discount comes off the booking. The reason stays on the record, marked as taken back.')
                    ->action(function (BookingLine $record): void {
                        $discount = Discount::query()->where('booking_line_id', $record->getKey())->first();

                        if ($discount instanceof Discount) {
                            app(BookingDesk::class)->reverseDiscount($discount);
                        } else {
                            // A discount line from before there were discount
                            // rows: nothing to stamp, so the line simply goes.
                            $record->delete();
                            $this->booking()->recalculateTotal();
                        }

                        Notification::make()->success()->title('Discount taken back')->send();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nothing itemised')
            ->emptyStateDescription('A booking from the checkout carries a seat line per traveller. Fees and extras are added here.');
    }

    private function booking(): Booking
    {
        /** @var Booking */
        return $this->getOwnerRecord();
    }
}
