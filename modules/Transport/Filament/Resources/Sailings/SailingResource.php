<?php

namespace Modules\Transport\Filament\Resources\Sailings;

use App\Exceptions\DeskRefusal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Transport\Filament\Resources\Sailings\Pages\ManageSailings;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Services\TransportDesk;
use UnitEnum;

/**
 * The timetable — docs/TRANSPORT_PLAN.md, T1: one sailing at a time, or a
 * run of them from a timetable; booking seats for a caller; cancelling a
 * sailing with a reason.
 */
class SailingResource extends Resource
{
    protected static ?string $model = Sailing::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Sailings';

    protected static ?string $modelLabel = 'sailing';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('transport.viewAny') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('transport.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<int, string> */
    public static function routeOptions(): array
    {
        return BoatRoute::query()->where('is_active', true)->with(['origin', 'destination'])->get()
            ->mapWithKeys(fn (BoatRoute $route): array => [$route->getKey() => $route->label()])->all();
    }

    /** @return array<int, string> */
    public static function vesselOptions(): array
    {
        return Vessel::query()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (Vessel $vessel): array => [$vessel->getKey() => $vessel->name.' ('.$vessel->seats.' seats)'])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('boat_route_id')->label('Route')->options(fn (): array => self::routeOptions())->required(),
            Select::make('vessel_id')->label('Boat')->options(fn (): array => self::vesselOptions())->required(),
            DateTimePicker::make('departs_at')->label('Leaves at')->required()->seconds(false)->minDate(now()),
            TextInput::make('seats_total')->label('Seats on sale')->integer()->required()->minValue(1)->maxValue(500),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['route.origin', 'route.destination', 'vessel']))
            ->columns([
                TextColumn::make('departs_at')->label('Leaves')->dateTime('D j M, H:i')->sortable(),
                TextColumn::make('route')->label('Route')->state(fn (Sailing $record): string => $record->route->label()),
                TextColumn::make('vessel.name')->label('Boat'),
                TextColumn::make('seats')->label('Seats')->alignEnd()
                    ->state(fn (Sailing $record): string => $record->seats_taken.' / '.$record->seats_total),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state): string => Sailing::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Sailing::CANCELLED => 'danger',
                        Sailing::DEPARTED => 'gray',
                        default => 'success',
                    }),
            ])
            ->defaultSort('departs_at')
            ->filters([
                SelectFilter::make('status')->options(Sailing::STATUSES),
            ])
            ->recordActions([self::bookAction(), self::cancelAction()])
            ->emptyStateHeading('No sailings yet')
            ->emptyStateDescription('Add one, or add a timetable to create a run of them.');
    }

    /** Seats for somebody on the phone or at the counter. */
    public static function bookAction(): Action
    {
        return Action::make('book')
            ->label('Book seats')
            ->icon('heroicon-o-ticket')
            ->visible(fn (Sailing $record): bool => $record->isOnSale() && auth()->user()?->can('transport.book') === true)
            ->modalDescription(fn (Sailing $record): string => $record->seatsLeft().' seats left. The seats are held until the booking is confirmed or paid.')
            ->schema([
                TextInput::make('contact_name')->label('Booked by')->required()->maxLength(120),
                TextInput::make('contact_phone')->label('Phone')->tel()->required()->maxLength(30),
                Repeater::make('passengers')
                    ->schema([
                        TextInput::make('full_name')->label('Passenger')->required()->maxLength(120),
                        Toggle::make('is_child')->label('Child'),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->addActionLabel('Add a passenger'),
            ])
            ->action(function (Sailing $record, array $data): void {
                try {
                    $booking = app(TransportDesk::class)->book($record, array_values($data['passengers'] ?? []), (string) $data['contact_name'], (string) $data['contact_phone'], null, auth()->user());
                    Notification::make()->success()->title('Booked: '.$booking->reference)->body($booking->seats.' seats, '.$booking->total().'.')->send();
                } catch (DeskRefusal $refusal) {
                    Notification::make()->danger()->title($refusal->getMessage())->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel sailing')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Sailing $record): bool => in_array($record->status, [Sailing::SCHEDULED, Sailing::BOARDING], true) && auth()->user()?->can('transport.manage') === true)
            ->modalDescription('Every booking on it is cancelled with it.')
            ->schema([Textarea::make('reason')->label('Why')->required()->maxLength(500)->rows(2)])
            ->action(function (Sailing $record, array $data): void {
                try {
                    $count = app(TransportDesk::class)->cancelSailing($record, (string) $data['reason']);
                    Notification::make()->success()->title('Sailing cancelled')->body($count.' booking(s) cancelled with it.')->send();
                } catch (DeskRefusal $refusal) {
                    Notification::make()->danger()->title($refusal->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageSailings::route('/')];
    }
}
