<?php

namespace App\Filament\Resources\Rooms\Tables;

use App\Models\DepartureHotel;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Traveller;
use App\Support\Rooming;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hotel.name')
                    ->label('Hotel')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Room $record): string => sprintf(
                        '%s · %s',
                        $record->hotel->cityLabel(),
                        $record->hotel->departure->date_start->format('j M Y'),
                    )),

                TextColumn::make('label')->label('Room')->searchable()->sortable(),

                TextColumn::make('gender')
                    ->label('For')
                    ->badge()
                    // `->state()` rather than `->formatStateUsing()`: a null
                    // column state short-circuits formatting, so the
                    // undesignated room — the one case this column is
                    // coloured for — rendered as an empty cell. Found by
                    // opening the screen; no assertion on a formatter that
                    // is never called can fail.
                    ->state(fn (Room $record): string => $record->genderLabel())
                    // Undesignated is a problem, not a blank.
                    ->color(fn (Room $record): string => $record->gender === null ? 'warning' : 'gray'),

                TextColumn::make('beds')
                    ->label('Beds')
                    ->alignCenter()
                    ->state(fn (Room $record): string => sprintf(
                        '%d of %d',
                        $record->assignments->count(),
                        $record->capacity,
                    ))
                    ->color(fn (Room $record): string => $record->isOverfull() ? 'danger' : 'gray'),

                TextColumn::make('occupants')
                    ->label('Who is in it')
                    ->state(fn (Room $record): array => $record->occupants()->pluck('full_name')->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Empty'),
            ])
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('departure_hotel_id')
                    ->label('Hotel')
                    ->searchable()
                    ->options(fn (): array => DepartureHotel::query()
                        ->with('departure')
                        ->get()
                        ->mapWithKeys(fn (DepartureHotel $hotel): array => [
                            $hotel->getKey() => sprintf(
                                '%s — %s (%s)',
                                $hotel->cityLabel(),
                                $hotel->name,
                                $hotel->departure->date_start->format('j M Y'),
                            ),
                        ])
                        ->all()),

                Filter::make('upcoming')
                    ->label('Upcoming departures only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'hotel.departure',
                        fn ($q) => $q->where('date_start', '>=', now()->startOfDay()),
                    )),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::assignAction(),
                    self::removeAction(),
                    self::checkAction(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('No rooms yet')
            ->emptyStateDescription('A room belongs to one hotel stay, so a party can be in a four-bed room in Makkah and a two-bed room in Madinah.');
    }

    /**
     * Put somebody in this room — and, unless that is switched off, the
     * rest of their family with them (batch C: families are roomed
     * together by default).
     *
     * The list offers only travellers confirmed on this departure who are
     * not already in a room in this hotel — which is most of the way to
     * preventing the double-booking the conflict report otherwise has to
     * catch after the fact — and the family brought along is drawn from
     * the same list, so nobody already roomed here is moved.
     */
    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Put somebody in')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('rooming.update') === true)
            ->schema([
                Select::make('traveller_id')
                    ->label('Traveller')
                    ->required()
                    ->searchable()
                    ->options(fn (Room $record): array => self::unroomedOptions($record)),

                Toggle::make('with_family')
                    ->label('Bring the rest of their family')
                    ->default(true)
                    ->helperText('Everyone else the same person booked who has no room in this hotel yet. Switch it off to room somebody apart on purpose — a grown son with the men, say.'),
            ])
            ->action(function (Room $record, array $data): void {
                $traveller = Traveller::find($data['traveller_id']);
                $ids = [(int) $data['traveller_id']];

                if ($traveller instanceof Traveller && (bool) ($data['with_family'] ?? true)) {
                    foreach (self::unroomed($record) as $other) {
                        if ((int) $other->customer_id === (int) $traveller->customer_id && ! in_array((int) $other->getKey(), $ids, true)) {
                            $ids[] = (int) $other->getKey();
                        }
                    }
                }

                foreach ($ids as $id) {
                    RoomAssignment::create([
                        'room_id' => $record->getKey(),
                        'traveller_id' => $id,
                        'booking_id' => self::bookingIdFor($record, $id),
                        'assigned_by' => auth()->id(),
                    ]);
                }

                $others = count($ids) - 1;

                Notification::make()
                    ->success()
                    ->title(($traveller->full_name ?? 'They')
                        .($others === 0 ? ' is' : ' and '.$others.' of their family are')
                        .' in room '.$record->label)
                    // Said rather than blocked: over capacity is sometimes
                    // deliberate for a night, and refusing it outright is
                    // how staff go round the system with a spreadsheet.
                    ->body($record->fresh()->isOverfull()
                        ? 'That is more people than there are beds.'
                        : null)
                    ->send();
            });
    }

    private static function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Take somebody out')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (Room $record): bool => $record->assignments->isNotEmpty()
                && auth()->user()?->can('rooming.update') === true)
            ->schema([
                Select::make('traveller_id')
                    ->label('Who')
                    ->required()
                    ->options(fn (Room $record): array => $record->occupants()
                        ->pluck('full_name', 'id')
                        ->all()),
            ])
            ->action(function (Room $record, array $data): void {
                RoomAssignment::where('room_id', $record->getKey())
                    ->where('traveller_id', $data['traveller_id'])
                    ->delete();

                Notification::make()->success()->title('Taken out of room '.$record->label)->send();
            });
    }

    /**
     * Everything wrong with this hotel's rooming, not just this room.
     *
     * A room is only correct relative to the rest of the list: somebody in
     * two rooms and somebody in none are both invisible from inside a
     * single room.
     */
    private static function checkAction(): Action
    {
        return Action::make('check')
            ->label('Check the whole hotel')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->modalHeading(fn (Room $record): string => 'Rooming for '.$record->hotel->name)
            ->modalContent(fn (Room $record) => view('filament.rooming-check', [
                'hotel' => $record->hotel,
                'problems' => Rooming::problemsWith($record->hotel),
                // Whole literal strings, not a key built by concatenation:
                // TranslationQualityTest rejects the latter, and the day a
                // new problem type is added a raw key would appear on the
                // screen instead of a sentence.
                'labels' => [
                    Rooming::OVER_CAPACITY => 'More people than beds',
                    Rooming::MIXED_GENDER => 'Men and women in one room',
                    Rooming::UNDESIGNATED => 'Nobody has said who the room is for',
                    Rooming::UNACCOMPANIED_CHILD => 'A child with no adult of their family',
                    Rooming::DOUBLE_BOOKED => 'In two rooms at once',
                    Rooming::UNROOMED => 'No room at all',
                ],
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    /**
     * Travellers on this departure with no room in this hotel yet, each
     * with the family they travel in, so the right Mariyam is picked.
     *
     * @return array<int, string>
     */
    private static function unroomedOptions(Room $room): array
    {
        return self::unroomed($room)
            ->mapWithKeys(fn (Traveller $traveller): array => [
                $traveller->getKey() => $traveller->full_name.' · '.$traveller->customer->name.'\'s family',
            ])
            ->all();
    }

    /**
     * Who is owed a bed on this departure and has none in this hotel.
     *
     * @return EloquentCollection<int, Traveller>
     */
    private static function unroomed(Room $room): EloquentCollection
    {
        $hotel = $room->hotel;

        $alreadyRoomed = $hotel->rooms->flatMap(
            fn (Room $other): array => $other->assignments->pluck('traveller_id')->all(),
        )->unique();

        return EloquentCollection::make(Rooming::travellersOwedABed($hotel->departure)
            ->reject(fn (Traveller $traveller): bool => $alreadyRoomed->contains($traveller->getKey()))
            ->values()
            ->all())
            ->load('customer');
    }

    /** Which booking put this traveller on this departure. */
    private static function bookingIdFor(Room $room, int $travellerId): ?int
    {
        return $room->hotel->departure->bookings()
            ->whereHas('travellers', fn ($query) => $query->where('traveller_id', $travellerId))
            ->value('id');
    }
}
