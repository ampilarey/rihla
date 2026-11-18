<?php

namespace App\Filament\Resources\Excursions\Tables;

use App\Filament\Resources\ExcursionBookings\ExcursionBookingResource;
use App\Filament\Support\DepartureOptions;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Departure;
use App\Models\DepartureExcursion;
use App\Models\ExcursionBooking;
use App\Models\ExcursionParticipant;
use App\Services\Operations\Buses;
use App\Services\Operations\Excursions;
use App\Services\Operations\Families;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExcursionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('departure.package'))
            ->columns([
                TextColumn::make('title')
                    ->label('Trip')
                    ->searchable()
                    ->wrap()
                    ->description(fn (DepartureExcursion $record): string => DepartureOptions::label($record->departure)
                        .($record->scheduled_on ? ' · '.$record->scheduled_on->format('j M') : '')),

                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (DepartureExcursion $record): string => $record->priceLabel()),

                TextColumn::make('places')
                    ->label('Going')
                    ->alignCenter()
                    ->state(fn (DepartureExcursion $record): string => $record->placesTaken().($record->capacity ? ' of '.$record->capacity : '')),

                TextColumn::make('money')
                    ->label('Paid')
                    ->state(fn (DepartureExcursion $record): string => self::moneyWords($record))
                    ->visibleFrom('md'),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('departure_id')
                    ->label('Departure')
                    ->searchable()
                    ->options(fn (): array => DepartureOptions::all()),

                Filter::make('upcoming')
                    ->label('Upcoming departures only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereHas('departure', Departure::notOverByFilter(now()->startOfDay()))),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::signUpAction(),
                    Action::make('bookings')
                        ->label('Trip bookings')
                        ->icon('heroicon-o-banknotes')
                        ->color('gray')
                        ->visible(fn (DepartureExcursion $record): bool => auth()->user()?->can('booking.viewAny') === true && $record->bookings()->exists())
                        ->url(fn (DepartureExcursion $record): string => ExcursionBookingResource::getUrl('index', ['filters' => ['departure_excursion_id' => ['value' => $record->getKey()]]])),
                    EditAction::make(),
                    DeleteAction::make()
                        // Restricted by the database too: places somebody may
                        // have paid for are a financial record.
                        ->visible(fn (DepartureExcursion $record): bool => auth()->user()?->can('departure.delete') === true && ! $record->bookings()->exists()),
                ]),
            ])
            ->emptyStateHeading('No additional trips yet')
            ->emptyStateDescription('Taif, Jeddah, a Ziyarah: add one to a departure, then sign people up. Each family\'s places get their own invoice and payments, in the trip\'s currency.');
    }

    /** "SAR 450 of SAR 1,200" across the trip's bookings that stand. */
    private static function moneyWords(DepartureExcursion $record): string
    {
        $bookings = $record->bookings()->where('status', '!=', ExcursionBooking::CANCELLED)->get(['total_minor', 'paid_minor', 'currency']);

        if ($bookings->isEmpty()) {
            return '—';
        }

        return Money::ofMinor((int) $bookings->sum('paid_minor'), $record->currency)->format()
            .' of '.Money::ofMinor((int) $bookings->sum('total_minor'), $record->currency)->format();
    }

    private static function signUpAction(): Action
    {
        return Action::make('signUp')
            ->label('Sign people up')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('booking.update') === true)
            ->modalWidth('2xl')
            ->modalHeading(fn (DepartureExcursion $record): string => 'Sign people up for '.$record->title)
            ->modalDescription(fn (DepartureExcursion $record): string => $record->priceLabel()
                .($record->placesLeft() !== null ? ' · '.$record->placesLeft().' places left' : ''))
            ->schema([
                CheckboxList::make('ids')
                    ->label('Not yet going')
                    ->required()
                    ->searchable()
                    ->bulkToggleable()
                    ->options(fn (DepartureExcursion $record): array => self::pool($record)
                        ->sortBy(fn (BookingTraveller $member): string => $member->booking->customer->name.' '.$member->traveller->full_name)
                        ->mapWithKeys(fn (BookingTraveller $member): array => [
                            $member->getKey() => $member->traveller->full_name
                                .' · '.$member->booking->customer->name.'\'s family'
                                .($member->booking->status === Booking::HELD ? ' · not yet confirmed' : ''),
                        ])
                        ->all()),

                Toggle::make('with_family')
                    ->label('Bring the rest of their family')
                    ->default(false)
                    ->helperText('Off by default: not everybody in a family goes on every trip.'),
            ])
            ->action(function (DepartureExcursion $record, array $data): void {
                $ids = array_values(array_map('intval', (array) $data['ids']));

                if ((bool) ($data['with_family'] ?? false)) {
                    $ids = app(Families::class)->widen($ids, self::pool($record));
                }

                $result = app(Excursions::class)->signUp($record, $ids);

                Notification::make()
                    ->success()
                    ->title($result['added'].' signed up for '.$record->title)
                    ->body(trim(($result['bookings'] !== [] ? 'Trip bookings: '.implode(', ', $result['bookings']).'. ' : '').implode(' ', $result['skipped'])) ?: null)
                    ->send();
            });
    }

    /**
     * Everybody on the departure who is not signed up yet.
     *
     * @return Collection<int, BookingTraveller>
     */
    private static function pool(DepartureExcursion $record): Collection
    {
        $going = ExcursionParticipant::query()
            ->whereIn('excursion_booking_id', $record->bookings()->select('id'))
            ->pluck('booking_traveller_id')
            ->all();

        return app(Buses::class)->candidates($record->departure)
            ->reject(fn (BookingTraveller $member): bool => in_array($member->getKey(), $going, true));
    }
}
