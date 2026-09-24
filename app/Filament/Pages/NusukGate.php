<?php

namespace App\Filament\Pages;

use App\Filament\Support\DepartureOptions;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\NusukPermit;
use App\Support\Rooming;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Nusuk compliance gate's own screen — §5.4b, §8.3.
 *
 * Nusuk wants a departure's accommodation and transport recorded before a
 * permit can be requested, and {@see NusukPermit::missingPrerequisites()}
 * refuses a request until they are. The two dates used to be editable only
 * inside a package's departure form — which the role that deals with Nusuk
 * cannot open, because Visa Staff do not hold `package.*`. So the one
 * person able to clear the gate had no way to reach it.
 *
 * This screen is the gate on its own: every upcoming departure, whether
 * each prerequisite is recorded, and how many of its travellers already
 * hold an Umrah permit. What is recorded is *when Rihla entered it in
 * Nusuk* — whether what was entered is compliant is Nusuk's judgement, and
 * nothing here claims to make it.
 */
class NusukGate extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Nusuk gate';

    protected static ?string $title = 'Nusuk gate';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected string $view = 'filament.pages.nusuk-gate';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('departure.nusuk') === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Upcoming departures with a prerequisite still missing. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $open = Departure::query()
            ->where('date_end', '>=', today())
            ->get()
            ->filter(fn (Departure $departure): bool => NusukPermit::missingPrerequisites($departure) !== [])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Departure::query()->where('date_end', '>=', today())->with('package'))
            ->defaultSort('date_start')
            ->columns([
                TextColumn::make('label')
                    ->label('Departure')
                    ->state(fn (Departure $record): string => DepartureOptions::label($record))
                    ->wrap(),

                self::recordedColumn('nusuk_accommodation_recorded_at', 'Accommodation'),

                self::recordedColumn('nusuk_transport_recorded_at', 'Transport'),

                TextColumn::make('permits')
                    ->label('Umrah permits')
                    ->state(fn (Departure $record): string => self::permitCount($record)),
            ])
            ->recordActions([
                Action::make('record')
                    ->label('Record in Nusuk')
                    ->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (Departure $record): array => [
                        'nusuk_accommodation_recorded_at' => $record->nusuk_accommodation_recorded_at,
                        'nusuk_transport_recorded_at' => $record->nusuk_transport_recorded_at,
                    ])
                    ->schema([
                        DateTimePicker::make('nusuk_accommodation_recorded_at')
                            ->label('Accommodation recorded')
                            ->seconds(false)
                            ->helperText('Leave blank until it actually is.'),
                        DateTimePicker::make('nusuk_transport_recorded_at')
                            ->label('Transport recorded')
                            ->seconds(false)
                            ->helperText('Either one missing blocks every permit on this departure.'),
                    ])
                    ->action(function (Departure $record, array $data): void {
                        abort_unless(static::canAccess(), 403);

                        $record->update([
                            'nusuk_accommodation_recorded_at' => $data['nusuk_accommodation_recorded_at'] ?? null,
                            'nusuk_transport_recorded_at' => $data['nusuk_transport_recorded_at'] ?? null,
                        ]);

                        Notification::make()->success()->title('Saved')->send();
                    }),
            ])
            ->emptyStateHeading('No upcoming departures');
    }

    private static function recordedColumn(string $column, string $label): TextColumn
    {
        return TextColumn::make($column)
            ->label($label)
            ->state(fn (Departure $record): string => $record->{$column}?->format('j M Y') ?? (self::required($column) ? 'Not yet — blocks permits' : 'Not required'))
            ->color(fn (Departure $record): string => $record->{$column} !== null ? 'success' : (self::required($column) ? 'danger' : 'gray'));
    }

    /** Whether config says this prerequisite gates a permit (§5.4b). */
    private static function required(string $column): bool
    {
        return match ($column) {
            'nusuk_accommodation_recorded_at' => (bool) config('nusuk.prerequisites.accommodation'),
            'nusuk_transport_recorded_at' => (bool) config('nusuk.prerequisites.transport'),
            default => true,
        };
    }

    /** "3 of 12", counted against the people actually travelling. */
    private static function permitCount(Departure $departure): string
    {
        $travelling = Rooming::travellersOwedABed($departure)->count();

        if ($travelling === 0) {
            return 'Nobody travelling yet';
        }

        $issued = NusukPermit::query()
            ->where('kind', NusukPermit::UMRAH)
            ->where('status', NusukPermit::ISSUED)
            ->whereIn('booking_id', Booking::query()->where('departure_id', $departure->getKey())->select('id'))
            ->distinct()
            ->count('traveller_id');

        return $issued.' of '.$travelling;
    }
}
