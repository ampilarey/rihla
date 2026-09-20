<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Filament\Concerns\EditsTranslations;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\ItineraryItem;
use App\Models\NusukPermit;
use App\Models\PriceTier;
use App\Support\Money;
use App\Support\TravelReadiness;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Each dated run of a package, edited inside the package that owns it.
 *
 * A relation manager rather than a resource of its own, because a departure
 * has no meaning apart from its package and nobody goes looking for one
 * without knowing which package it belongs to.
 *
 * Prices, hotels and the itinerary are repeaters on the departure form. They
 * are small, always edited together, and splitting them into three more
 * screens would mean four clicks to answer "what does this cost and where do
 * we stay".
 */
class DeparturesRelationManager extends RelationManager
{
    protected static string $relationship = 'departures';

    protected static ?string $title = 'Departures';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                DatePicker::make('date_start')->label('Departs')->required()->native(false),
                DatePicker::make('date_end')->label('Returns')->required()->native(false)
                    ->afterOrEqual('date_start'),
                TextInput::make('airline')->maxLength(255),
                Select::make('status')
                    ->options(array_combine(Departure::STATUSES, array_map('ucfirst', Departure::STATUSES)))
                    ->default(Departure::STATUS_UPCOMING)
                    ->required(),
                Toggle::make('is_published')
                    ->helperText('Hidden on the site until this is on, even if the package is published.'),
            ]),

            Section::make('Seats')
                ->description('A held seat is neither free nor sold. "Held" is moved by the booking engine under a row lock and must not be typed here [R-2].')
                ->columns(3)
                ->schema([
                    TextInput::make('capacity_total')->label('Total seats')->numeric()->minValue(0)->default(0)
                        ->helperText('Leave at 0 to show no seats bar at all.'),
                    TextInput::make('capacity_confirmed')->label('Confirmed')->numeric()->minValue(0)->default(0),
                    TextInput::make('capacity_held')->label('Held')->numeric()->minValue(0)->default(0)->disabled()
                        ->helperText('Set by the booking engine.'),
                ]),

            Section::make('Nusuk')
                ->description('§5.4b: accommodation and transport have to be entered in Nusuk before any Umrah permit can be requested for anybody on this departure. These record that it was done — the dates are a note of when, not a check that Nusuk agrees.')
                ->columns(2)
                ->schema([
                    DateTimePicker::make('nusuk_accommodation_recorded_at')
                        ->label('Accommodation recorded')
                        ->native(false)
                        ->seconds(false)
                        // Shown to everybody, editable only by the roles that
                        // deal with Nusuk. A Content Manager who edits the
                        // website has no business asserting a dealing with a
                        // Saudi system, and hiding it outright would leave
                        // them unable to see why a permit is stuck.
                        ->disabled(fn (): bool => auth()->user()?->can('departure.nusuk') !== true)
                        ->helperText('Leave blank until it actually is.'),

                    DateTimePicker::make('nusuk_transport_recorded_at')
                        ->label('Transport recorded')
                        ->native(false)
                        ->seconds(false)
                        ->disabled(fn (): bool => auth()->user()?->can('departure.nusuk') !== true)
                        ->helperText('Either one missing blocks every permit on this departure.'),
                ]),

            Section::make('Prices')
                ->description('Per room occupancy. The cheapest is what the site shows as the "from" price.')
                ->schema([
                    Repeater::make('priceTiers')
                        ->relationship()
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            Select::make('occupancy')
                                ->options(array_combine(PriceTier::OCCUPANCIES, array_map('ucfirst', PriceTier::OCCUPANCIES)))
                                ->required(),
                            Select::make('pax_type')
                                ->label('Traveller')
                                ->options(array_combine(PriceTier::PAX_TYPES, array_map('ucfirst', PriceTier::PAX_TYPES)))
                                ->default('adult')
                                ->required(),
                            // Shown and typed in whole rufiyaa; stored in
                            // laari. The conversion lives here and in
                            // App\Support\Money, nowhere else — see [R-7].
                            TextInput::make('amount_minor')
                                ->label('Price')
                                ->numeric()
                                ->required()
                                ->prefix('MVR')
                                ->helperText('Whole rufiyaa.')
                                ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : intdiv($state, 100))
                                ->dehydrateStateUsing(fn ($state): int => Money::ofMajor((int) $state)->minor),
                        ])
                        ->addActionLabel('Add a price')
                        ->defaultItems(0),
                ]),

            Section::make('Hotels')
                ->description('Distance from the Haram is what pilgrims actually compare. No competitor publishes it.')
                ->schema([
                    Repeater::make('hotels')
                        ->relationship()
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            Select::make('city')->options([
                                DepartureHotel::CITY_MAKKAH => 'Makkah',
                                DepartureHotel::CITY_MADINAH => 'Madinah',
                            ])->required(),
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('rating')->maxLength(10)->placeholder('5-star'),
                            TextInput::make('distance_metres')->label('Distance to Haram')->numeric()->suffix('m'),
                            TextInput::make('walk_minutes')->label('Walk')->numeric()->suffix('min'),
                            TextInput::make('nights')->numeric()->minValue(1),
                        ])
                        ->addActionLabel('Add a hotel')
                        ->orderColumn('sort_order')
                        ->defaultItems(0),
                ]),

            Section::make('Itinerary')
                ->description('Day by day. The plan calls this the single most requested thing pilgrims ask about.')
                ->schema([
                    Repeater::make('itinerary')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('day_number')->label('Day')->numeric()->minValue(1)->required(),
                            Select::make('city')->options([
                                'makkah' => 'Makkah', 'madinah' => 'Madinah',
                                'male' => 'Malé', 'transit' => 'In transit',
                            ]),
                            Tabs::make('Translations')->tabs([
                                self::itineraryLocaleTab('en', 'English', required: true),
                                self::itineraryLocaleTab('dv', 'Dhivehi', required: false),
                            ]),
                        ])
                        // Nested translatable rows need the same pruning as
                        // the package itself: both locale tabs are always
                        // submitted, and a stored empty Dhivehi title stops
                        // the fallback on the itinerary too.
                        ->mutateRelationshipDataBeforeCreateUsing(
                            fn (array $data): array => EditsTranslations::withoutEmptyLocales($data, (new ItineraryItem)->translatable),
                        )
                        ->mutateRelationshipDataBeforeSaveUsing(
                            fn (array $data): array => EditsTranslations::withoutEmptyLocales($data, (new ItineraryItem)->translatable),
                        )
                        ->addActionLabel('Add a day')
                        ->orderColumn('day_number')
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): ?string => filled($state['day_number'] ?? null)
                            ? 'Day '.$state['day_number']
                            : null),
                ]),
        ]);
    }

    private static function itineraryLocaleTab(string $locale, string $label, bool $required): Tab
    {
        $rtl = $locale === 'dv' ? ['dir' => 'rtl', 'lang' => 'dv'] : [];

        return Tab::make($label)->schema([
            TextInput::make("title.{$locale}")->label('Title')->required($required)
                ->maxLength(255)->extraInputAttributes($rtl),
            Textarea::make("description.{$locale}")->label('Description')->rows(3)
                ->extraInputAttributes($rtl),
        ]);
    }

    /**
     * §5.4a's gate, asked on demand: "can this departure actually fly?"
     *
     * An action rather than a column, because answering it costs a handful
     * of queries per traveller and paying that on every row of every
     * departure list — to draw a tick almost nobody reads — is the wrong
     * trade. Read-only: there is no "mark ready" to press, because
     * readiness is computed from the underlying records and never stored.
     */
    private static function readinessAction(): Action
    {
        return Action::make('readiness')
            ->label('Readiness')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('gray')
            ->modalHeading('Can this departure fly?')
            ->modalContent(fn (Departure $record) => view('filament.departure-readiness', [
                'blockers' => TravelReadiness::departureBlockers($record),
                'missingPrerequisites' => NusukPermit::missingPrerequisites($record),
                'labels' => [
                    TravelReadiness::PASSPORT => 'a usable passport',
                    TravelReadiness::VISA => 'a visa',
                    TravelReadiness::PERMIT => 'an Umrah permit',
                ],
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date_start')->label('Departs')->date('d M Y')->sortable(),
                TextColumn::make('nights')->alignCenter()->badge(),
                TextColumn::make('airline')->placeholder('—')->toggleable(),
                TextColumn::make('lead_price')
                    ->label('From')
                    ->state(fn (Departure $record): string => $record->lead_price?->format() ?? '—'),
                TextColumn::make('seats')
                    ->label('Seats')
                    ->state(fn (Departure $record): string => $record->has_capacity
                        ? $record->seats_taken.' of '.$record->capacity_total
                        : 'not tracked'),
                TextColumn::make('nusuk')
                    ->label('Nusuk')
                    ->badge()
                    ->state(fn (Departure $record): string => NusukPermit::missingPrerequisites($record) === []
                        ? 'recorded'
                        : 'no '.implode(' or ', NusukPermit::missingPrerequisites($record)))
                    ->color(fn (Departure $record): string => NusukPermit::missingPrerequisites($record) === []
                        ? 'success'
                        : 'warning')
                    ->toggleable(),

                IconColumn::make('is_published')->label('Live')->boolean(),
            ])
            ->defaultSort('date_start')
            ->headerActions([CreateAction::make()])
            ->recordActions([self::readinessAction(), EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No departures yet')
            ->emptyStateDescription('A departure is one dated run of this package.');
    }
}
