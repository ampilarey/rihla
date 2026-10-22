<?php

namespace App\Filament\Resources\BusGroups\Tables;

use App\Filament\Support\DepartureOptions;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Services\Operations\Buses;
use App\Services\Operations\Families;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['departure.package', 'departure.tourLeader']))
            ->columns([
                // Bus first: on a phone the departure column took the whole
                // width and pushed the bus itself off the right edge, so the
                // departure rides under the name there and has a sortable
                // column of its own from a tablet up.
                TextColumn::make('name')
                    ->label('Bus')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (BusGroup $record): string => DepartureOptions::label($record->departure)
                        .' · Leader: '.($record->leaderLabel() ?? 'nobody yet')
                        .($record->leader_phone ? ' · '.$record->leader_phone : '')),

                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->sortable()
                    ->state(fn (BusGroup $record): string => DepartureOptions::label($record->departure))
                    ->visibleFrom('md'),

                TextColumn::make('on_board')
                    ->label('On board')
                    ->alignCenter()
                    ->state(fn (BusGroup $record): string => $record->members()->count().($record->capacity ? ' of '.$record->capacity : ''))
                    ->color(fn (BusGroup $record): string => $record->isOverfull() ? 'danger' : 'gray'),

                TextColumn::make('boarded')
                    ->label('Boarded')
                    ->alignCenter()
                    ->state(fn (BusGroup $record): string => (string) $record->checkedInCount())
                    ->visibleFrom('sm'),

                TextColumn::make('people')
                    ->label('Who is on it')
                    ->state(fn (BusGroup $record): array => $record->members()->with('traveller')->get()
                        ->map(fn (BookingTraveller $member): string => $member->traveller->full_name.($member->isCheckedIn() ? ' ✓' : ''))
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(8)
                    ->bulleted()
                    ->placeholder('Empty')
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
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'departure',
                        fn ($q) => $q->where('date_end', '>=', now()->startOfDay()),
                    )),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::assignAction(),
                    self::removeAction(),
                    self::boardAction(),
                    self::listAction(),
                    self::tagsAction(),
                    self::cardsAction(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('No buses yet')
            ->emptyStateDescription('Add Bus 1, Bus 2… for a departure, name who leads each, then put people on them from here.');
    }

    /**
     * Put people on this bus.
     *
     * Offers everybody holding a seat on the departure who is not already
     * on it, saying which bus they are on now. The rest of each chosen
     * person's family comes too unless that is switched off — families
     * travel together by default (batch C) — including from another bus.
     * The service refuses anybody on another departure and reports a coach
     * over capacity or a family split across two, rather than blocking
     * either: over capacity is sometimes deliberate for a short leg.
     */
    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Add people')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('bus.assign') === true)
            ->modalWidth('2xl')
            ->modalHeading(fn (BusGroup $record): string => 'Put people on '.$record->name)
            ->schema([
                CheckboxList::make('ids')
                    ->label('Not yet on this bus')
                    ->required()
                    ->searchable()
                    ->bulkToggleable()
                    ->options(fn (BusGroup $record): array => app(Buses::class)->candidates($record->departure)
                        ->reject(fn (BookingTraveller $member): bool => $member->bus_group_id === $record->getKey())
                        ->mapWithKeys(fn (BookingTraveller $member): array => [
                            $member->getKey() => $member->traveller->full_name
                                .' · '.$member->booking->customer->name.'\'s family'
                                .($member->busGroup ? ' · now on '.$member->busGroup->name : '')
                                .($member->booking->status === Booking::HELD ? ' · not yet confirmed' : ''),
                        ])
                        ->all()),

                Toggle::make('with_family')
                    ->label('Bring the rest of their family')
                    ->default(true)
                    ->helperText('Everyone else the same person booked onto this departure, from whichever bus they are on now. Switch it off to split a family on purpose.'),
            ])
            ->action(function (BusGroup $record, array $data): void {
                $ids = array_values(array_map('intval', (array) $data['ids']));

                if ((bool) ($data['with_family'] ?? true)) {
                    $ids = app(Families::class)->widen($ids, app(Buses::class)->candidates($record->departure)
                        ->reject(fn (BookingTraveller $member): bool => $member->bus_group_id === $record->getKey()));
                }

                $result = app(Buses::class)->assign($record, $ids);

                Notification::make()
                    ->success()
                    ->title($result['assigned'].' put on '.$record->name)
                    ->body(implode(' ', [...$result['warnings'], ...$result['skipped']]) ?: null)
                    ->send();
            });
    }

    private static function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Take somebody off')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (BusGroup $record): bool => auth()->user()?->can('bus.assign') === true && $record->members()->exists())
            ->schema([
                Select::make('id')
                    ->label('Who')
                    ->required()
                    ->options(fn (BusGroup $record): array => self::memberOptions($record)),
            ])
            ->action(function (BusGroup $record, array $data): void {
                $taken = app(Buses::class)->unassign([(int) $data['id']]);

                Notification::make()->success()->title($taken === 1 ? 'Taken off '.$record->name : 'Already off')->send();
            });
    }

    /** The tick at the coach door, for somebody with no tag to scan. */
    private static function boardAction(): Action
    {
        return Action::make('board')
            ->label('Mark boarded')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (BusGroup $record): bool => auth()->user()?->can('bus.checkIn') === true && $record->members()->exists())
            ->modalDescription('Pick who just got on. Picking somebody already ticked undoes the tick.')
            ->schema([
                Select::make('id')
                    ->label('Who')
                    ->required()
                    ->options(fn (BusGroup $record): array => self::memberOptions($record)),
            ])
            ->action(function (BusGroup $record, array $data): void {
                $member = $record->members()->with('traveller')->findOrFail((int) $data['id']);
                app(Buses::class)->toggleCheckIn($member);

                Notification::make()
                    ->success()
                    ->title($member->isCheckedIn() ? $member->traveller->full_name.' is on '.$record->name : 'Boarding undone for '.$member->traveller->full_name)
                    ->send();
            });
    }

    private static function listAction(): Action
    {
        return Action::make('list')
            ->label('Bus list')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('list.view') === true)
            ->url(fn (BusGroup $record): string => route('lists.bus', ['busGroup' => $record]), shouldOpenInNewTab: true);
    }

    private static function tagsAction(): Action
    {
        return Action::make('tags')
            ->label('Luggage tags')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->visible(fn (BusGroup $record): bool => auth()->user()?->can('card.create') === true && $record->members()->exists())
            ->url(fn (BusGroup $record): string => route('lists.cards', ['kind' => 'luggage', 'bus' => $record->getKey()]), shouldOpenInNewTab: true);
    }

    private static function cardsAction(): Action
    {
        return Action::make('cards')
            ->label('Customer cards')
            ->icon('heroicon-o-identification')
            ->color('gray')
            ->visible(fn (BusGroup $record): bool => auth()->user()?->can('card.create') === true && $record->members()->exists())
            ->url(fn (BusGroup $record): string => route('lists.cards', ['kind' => 'customer', 'bus' => $record->getKey()]), shouldOpenInNewTab: true);
    }

    /** @return array<int, string> */
    private static function memberOptions(BusGroup $record): array
    {
        return $record->members()->with('traveller')->get()
            ->sortBy(fn (BookingTraveller $member): string => $member->traveller->full_name)
            ->mapWithKeys(fn (BookingTraveller $member): array => [
                $member->getKey() => $member->traveller->full_name.($member->isCheckedIn() ? ' ✓ boarded' : ''),
            ])
            ->all();
    }
}
