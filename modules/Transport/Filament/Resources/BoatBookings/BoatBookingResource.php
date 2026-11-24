<?php

namespace Modules\Transport\Filament\Resources\BoatBookings;

use App\Exceptions\DeskRefusal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Transport\Filament\Resources\BoatBookings\Pages\ManageBoatBookings;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Services\TransportDesk;
use UnitEnum;

/**
 * Boat bookings — docs/TRANSPORT_PLAN.md, T1. Made from a sailing (staff)
 * or online (T2); here they are confirmed or cancelled, never edited or
 * deleted.
 */
class BoatBookingResource extends Resource
{
    protected static ?string $model = BoatBooking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Boat bookings';

    protected static ?string $modelLabel = 'boat booking';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 0;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('transport.viewAny') === true;
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
        $canBook = fn (): bool => auth()->user()?->can('transport.book') === true;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['sailing.route.origin', 'sailing.route.destination', 'tickets']))
            ->columns([
                TextColumn::make('reference')->searchable(),
                TextColumn::make('sailing')->label('Sailing')
                    ->state(fn (BoatBooking $record): string => $record->sailing->route->label().', '.$record->sailing->departs_at->format('D j M H:i')),
                TextColumn::make('contact_name')->label('Booked by')->description(fn (BoatBooking $record): string => $record->contact_phone)->searchable(),
                TextColumn::make('passengers')->label('Passengers')
                    ->state(fn (BoatBooking $record): string => $record->tickets->pluck('full_name')->join(', '))->wrap(),
                TextColumn::make('total_minor')->label('Total')->alignEnd()
                    ->state(fn (BoatBooking $record): string => (string) $record->total())
                    ->description(fn (BoatBooking $record): ?string => $record->paid_minor > 0 ? 'Owed '.$record->owed() : null),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => BoatBooking::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        BoatBooking::CONFIRMED => 'success',
                        BoatBooking::HELD => 'warning',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->filters([SelectFilter::make('status')->options(BoatBooking::STATUSES)])
            ->recordActions([
                Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check')
                    ->visible(fn (BoatBooking $record): bool => $record->status === BoatBooking::HELD && $canBook())
                    ->requiresConfirmation()
                    ->modalDescription('Paid at the counter, or trusted to pay: the seats stop expiring.')
                    ->action(fn (BoatBooking $record) => self::attempt(fn () => app(TransportDesk::class)->confirm($record), 'Confirmed')),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (BoatBooking $record): bool => $record->holdsSeats() && $canBook())
                    ->requiresConfirmation()
                    ->modalDescription('The seats go back on sale.')
                    ->action(fn (BoatBooking $record) => self::attempt(fn () => app(TransportDesk::class)->cancel($record), 'Cancelled')),
            ])
            ->emptyStateHeading('No boat bookings yet');
    }

    private static function attempt(callable $change, string $done): void
    {
        try {
            $change();
            Notification::make()->success()->title($done)->send();
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title($refusal->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageBoatBookings::route('/')];
    }
}
