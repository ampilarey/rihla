<?php

namespace Modules\Umrah\Filament\Resources\FlightGroups\Tables;

use App\Filament\Support\DepartureOptions;
use App\Support\CompanyRequest;
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
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\BookingTraveller;
use Modules\Umrah\Models\BusGroup;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\FlightGroup;
use Modules\Umrah\Services\Operations\Buses;
use Modules\Umrah\Services\Operations\Families;
use Modules\Umrah\Services\Operations\FlightGroups;

class FlightGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['departure.package', 'outboundFlight', 'returnFlight', 'buses'])
                ->withCount('members'))
            ->columns([
                TextColumn::make('name')
                    ->label('Group')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn (FlightGroup $record): string => DepartureOptions::label($record->departure).' · '.$record->flightsLabel()),

                TextColumn::make('members_count')
                    ->label('People')
                    ->alignCenter(),

                // Inline styles: the panel has no theme, so utilities do
                // nothing here (AGENTS.md).
                TextColumn::make('bus_list')
                    ->label('Buses')
                    ->html()
                    ->state(fn (FlightGroup $record): HtmlString => new HtmlString($record->buses->isEmpty()
                        ? '<span style="opacity:.6">no bus yet</span>'
                        : $record->buses->map(fn (BusGroup $bus): string => self::busChip($bus))->implode(' ')))
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
                    self::assignAction(),
                    self::removeAction(),
                    self::listAction('airline', 'Airline list', 'heroicon-o-document-text'),
                    self::listAction('office', 'Office list', 'heroicon-o-clipboard-document-list'),
                    // One letter per person in the group, across their bookings (Phase 3h).
                    Action::make('letters')
                        ->label('Travel letters')
                        ->icon('heroicon-o-envelope-open')
                        ->color('gray')
                        ->visible(fn (FlightGroup $record): bool => self::mayPrintLetters() && $record->members_count > 0)
                        ->url(fn (FlightGroup $record): string => CompanyRequest::route('lists.group-letters', ['flightGroup' => $record]), shouldOpenInNewTab: true),
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription('The people and buses stay on the departure, in no group.'),
                ]),
            ])
            ->emptyStateHeading('No flight groups yet')
            ->emptyStateDescription('A group is the people who fly together. Add the departure\'s flights first, then a group for each flight, then its buses under Buses.');
    }

    private static function mayPrintLetters(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('list.view') && $user->can('booking.view');
    }

    public static function busChip(BusGroup $bus): string
    {
        // Only a hex colour reaches a style attribute, whatever is stored.
        $colour = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $bus->colour) === 1 ? (string) $bus->colour : '#a8a29e';
        $dot = '<span style="display:inline-block;width:.75rem;height:.75rem;border-radius:9999px;vertical-align:middle;margin-inline-end:.25rem;background:'.$colour.'"></span>';

        return '<span style="white-space:nowrap;margin-inline-end:.5rem">'.$dot.e($bus->name).' '.$bus->members()->count().($bus->capacity ? '/'.$bus->capacity : '').'</span>';
    }

    /**
     * Put people in this group. The rest of each chosen person's family
     * comes too unless that is switched off; anybody leaving another
     * group's bus is counted, so the office knows a coach changed.
     */
    private static function assignAction(): Action
    {
        return Action::make('assign')
            ->label('Add people')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->can('bus.assign') === true)
            ->modalWidth('2xl')
            ->modalHeading(fn (FlightGroup $record): string => 'Put people in '.$record->name)
            ->schema([
                CheckboxList::make('ids')
                    ->label('Not yet in this group')
                    ->required()
                    ->searchable()
                    ->bulkToggleable()
                    ->options(fn (FlightGroup $record): array => self::pool($record)
                        ->sortBy(fn (BookingTraveller $member): string => $member->booking->customer->name.' '.$member->traveller->full_name)
                        ->mapWithKeys(fn (BookingTraveller $member): array => [
                            $member->getKey() => $member->traveller->full_name
                                .' · '.$member->booking->customer->name.'\'s family'
                                .($member->flightGroup ? ' · now in '.$member->flightGroup->name : '')
                                .($member->booking->status === Booking::HELD ? ' · not yet confirmed' : ''),
                        ])
                        ->all()),

                Toggle::make('with_family')
                    ->label('Bring the rest of their family')
                    ->default(true)
                    ->helperText('Everyone else the same person booked onto this departure. Switch it off to split a family on purpose.'),
            ])
            ->action(function (FlightGroup $record, array $data): void {
                $ids = array_values(array_map('intval', (array) $data['ids']));

                if ((bool) ($data['with_family'] ?? true)) {
                    $ids = app(Families::class)->widen($ids, self::pool($record));
                }

                $result = app(FlightGroups::class)->assign($record, $ids);

                Notification::make()
                    ->success()
                    ->title($result['assigned'].' put in '.$record->name)
                    ->body(trim(($result['offBus'] > 0 ? $result['offBus'].' came off a bus of their old group. ' : '').implode(' ', $result['skipped'])) ?: null)
                    ->send();
            });
    }

    private static function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Take somebody out')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (FlightGroup $record): bool => auth()->user()?->can('bus.assign') === true && $record->members()->exists())
            ->schema([
                Select::make('id')
                    ->label('Who')
                    ->required()
                    ->options(fn (FlightGroup $record): array => $record->members()->with('traveller')->get()
                        ->sortBy(fn (BookingTraveller $member): string => $member->traveller->full_name)
                        ->mapWithKeys(fn (BookingTraveller $member): array => [$member->getKey() => $member->traveller->full_name])
                        ->all()),
            ])
            ->action(function (FlightGroup $record, array $data): void {
                $taken = app(FlightGroups::class)->unassign([(int) $data['id']]);

                Notification::make()->success()->title($taken === 1 ? 'Taken out of '.$record->name : 'Already out')->send();
            });
    }

    private static function listAction(string $preset, string $label, string $icon): Action
    {
        return Action::make($preset.'List')
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn (FlightGroup $record): bool => auth()->user()?->can('list.view') === true && $record->members_count > 0)
            ->url(fn (FlightGroup $record): string => CompanyRequest::route('lists.manifest', [
                'departure' => $record->departure_id,
                'preset' => $preset,
                'group' => $record->getKey(),
            ]), shouldOpenInNewTab: true);
    }

    /**
     * Everybody on the departure who is not in this group yet.
     *
     * @return Collection<int, BookingTraveller>
     */
    private static function pool(FlightGroup $record): Collection
    {
        return app(Buses::class)->candidates($record->departure)
            ->reject(fn (BookingTraveller $member): bool => $member->flight_group_id === $record->getKey());
    }
}
