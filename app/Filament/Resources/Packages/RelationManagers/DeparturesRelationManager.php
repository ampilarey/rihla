<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Exceptions\IllegalDepartureTransition;
use App\Filament\Concerns\EditsTranslations;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\ItineraryItem;
use App\Models\NusukPermit;
use App\Models\PriceTier;
use App\Services\Departures\DepartureCopier;
use App\Support\Money;
use App\Support\TravelReadiness;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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

    /** @var array<string, string> */
    public const STAGE_COLOURS = [
        Departure::DRAFT => 'gray',
        Departure::OPEN => 'success',
        Departure::CLOSED => 'warning',
        Departure::DEPARTED => 'info',
        Departure::RETURNED => 'info',
        Departure::SETTLED => 'gray',
        Departure::CANCELLED => 'danger',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                DatePicker::make('date_start')->label('Departs')->required()->native(false),
                DatePicker::make('date_end')->label('Returns')->required()->native(false)
                    ->afterOrEqual('date_start'),
                TextInput::make('airline')->maxLength(255),
                // The stage is moved from the list, with a reason on the
                // record, never typed here: a form that could set "departed"
                // by accident would take the departure off sale by accident.
                Placeholder::make('stage')
                    ->label('Stage')
                    ->content(fn (?Departure $record): string => $record === null
                        ? 'A new departure is a draft: hidden and unbookable until you open it for booking from the list.'
                        : $record->statusLabel().self::stageWords($record)),
            ]),

            Section::make('Seats')
                ->description('A held seat is neither free nor sold. "Held" is moved by the booking engine under a row lock and must not be typed here [R-2].')
                ->columns(3)
                ->schema([
                    TextInput::make('capacity_total')->label('Total seats')->numeric()->default(0)
                        // Never below the seats already taken (site audit):
                        // the engine would then sell what does not exist.
                        ->minValue(fn (?Departure $record): int => $record === null ? 0 : $record->capacity_held + $record->capacity_confirmed)
                        ->helperText('Leave at 0 to show no seats bar at all. It cannot go below the seats already held or sold.'),
                    // Engine-owned like "held": typing it down made the
                    // allocator sell seats twice, and the database check
                    // cannot see an undercount (site audit).
                    TextInput::make('capacity_confirmed')->label('Confirmed')->numeric()->minValue(0)->default(0)->disabled()->dehydrated(false)
                        ->helperText('Moved by confirming and cancelling bookings.'),
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
                        ->columns(4)
                        ->schema([
                            // §16 Phase 15. A visitor's price is its own
                            // tier, saved in the visitor's currency by the
                            // model; `local` is every price before it.
                            Select::make('audience')
                                ->label('Price list')
                                ->options(['local' => 'Maldivians (MVR)', 'tourist' => 'Visitors ('.strtoupper((string) config('marketplace.currencies.tourist', 'USD')).')'])
                                ->default('local')
                                ->selectablePlaceholder(false)
                                // Never required: a tier entered without
                                // naming a list is a local price, as every
                                // tier before Phase 15 was.
                                ->dehydrateStateUsing(fn (?string $state): string => $state === 'tourist' ? 'tourist' : 'local')
                                ->live(),
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
                                ->prefix(fn ($get): string => $get('audience') === 'tourist' ? strtoupper((string) config('marketplace.currencies.tourist', 'USD')) : 'MVR')
                                ->helperText('Whole units — rufiyaa or dollars, per the price list.')
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

    /**
     * The same set-up on new dates: prices, hotels, itinerary, flights,
     * transfers, checklist and estimated costs, every date moved by the
     * same number of days. Bookings, holds, PNRs, ticks and the publish
     * switch stay behind — see DepartureCopier.
     */
    private static function copyAction(): Action
    {
        return Action::make('copy')
            ->label('Copy')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('departure.create') === true)
            ->modalHeading(fn (Departure $record): string => 'Copy the '.$record->date_start->format('j M Y').' departure')
            ->modalDescription(fn (Departure $record): string => DepartureCopier::carries($record) === []
                ? 'The dates, airline, seats and people travelling with the party are copied. Nothing else is set up on this departure yet.'
                : 'Copied with every date moved by the same number of days: '.implode(', ', DepartureCopier::carries($record))
                    .'. Bookings, seat holds, airline booking references and checklist ticks are not copied, and the copy is a draft until you open it for booking.')
            ->schema([
                DatePicker::make('date_start')
                    ->label('New departure date')
                    ->required()
                    ->native(false)
                    ->minDate(now()->toDateString())
                    ->helperText('The return date and every other date follow it.'),
            ])
            ->action(function (Departure $record, array $data): void {
                $copy = app(DepartureCopier::class)->copy($record, CarbonImmutable::parse($data['date_start']));

                Notification::make()
                    ->success()
                    ->title('Departure copied')
                    ->body('Departs '.$copy->date_start->format('j M Y').', returns '.$copy->date_end->format('j M Y').'. It is a draft until you open it for booking.')
                    ->send();
            });
    }

    /** "— closed by Aminath on 3 Nov: flights full", or nothing when nothing has moved it. */
    private static function stageWords(Departure $record): string
    {
        $event = $record->latestEvent();

        if ($event === null) {
            return '';
        }

        return ' — '.strtolower(Departure::label($event->to_status))
            .($event->user?->name ? ' by '.$event->user->name : '')
            .($event->created_at ? ' on '.$event->created_at->format('j M Y') : '')
            .($event->reason ? ': '.$event->reason : '');
    }

    /**
     * The moves along the lifecycle, each shown only where it is allowed
     * from and to somebody allowed to make it. The model refuses what the
     * list did not foresee and says why.
     */
    private static function stageActions(): ActionGroup
    {
        return ActionGroup::make([
            self::move(Departure::OPEN, 'Open for booking', 'heroicon-o-play', 'success',
                'It goes on the site and seats can be sold. Look at the prices, the seats and the dates first.'),
            self::move(Departure::CLOSED, 'Close bookings', 'heroicon-o-lock-closed', 'warning',
                'No more bookings; the ones it has carry on, and it comes off the site. Open it again if that changes.', reason: true),
            self::move(Departure::DEPARTED, 'Mark departed', 'heroicon-o-paper-airplane', 'info',
                'The party has flown. The board and the leader\'s tools follow the stage, not the date.'),
            self::move(Departure::RETURNED, 'Mark returned', 'heroicon-o-home', 'info',
                'The party is home. Finance settles it once the books are closed.'),
            self::move(Departure::SETTLED, 'Settle', 'heroicon-o-check-badge', 'success',
                'Its books are closed and nothing on it is anybody\'s work any more. It leaves the board.', permission: 'departure.settle'),
            self::move(Departure::CANCELLED, 'Cancel the departure', 'heroicon-o-x-circle', 'danger',
                'It is not running. Only possible once no booking on it is held or confirmed.', reason: true),
        ])
            ->label('Stage')
            ->icon('heroicon-o-arrow-right-circle')
            ->button()
            ->color('gray')
            ->size('sm');
    }

    private static function move(string $to, string $label, string $icon, string $colour, string $description, bool $reason = false, string $permission = 'departure.lifecycle'): Action
    {
        return Action::make('move_'.$to)
            ->label($label)
            ->icon($icon)
            ->color($colour)
            ->visible(fn (Departure $record): bool => $record->canBecome($to) && auth()->user()?->can($permission) === true)
            ->requiresConfirmation()
            ->modalHeading(fn (Departure $record): string => $label.' · '.$record->date_start->format('j M Y'))
            ->modalDescription($description)
            ->modalSubmitActionLabel($label)
            ->schema([
                Textarea::make('reason')
                    ->label($reason ? 'Why' : 'Note (optional)')
                    ->rows(2)
                    ->maxLength(255)
                    ->required($reason),
            ])
            ->action(function (Departure $record, array $data) use ($to, $label): void {
                try {
                    $record->transitionTo($to, isset($data['reason']) ? (string) $data['reason'] : null);
                } catch (IllegalDepartureTransition $e) {
                    Notification::make()->danger()->title('Not moved')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title($label)->body('It is now '.strtolower($record->statusLabel()).'.')->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date_start')->label('Departs')->date('d M Y')->sortable(),
                // Second, so a phone sees the stage without scrolling the
                // table sideways: it is the column that says what to do.
                TextColumn::make('status')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Departure::label($state))
                    ->color(fn (string $state): string => self::STAGE_COLOURS[$state] ?? 'gray'),
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

            ])
            ->defaultSort('date_start')
            ->filters([
                SelectFilter::make('status')->label('Stage')->options(Departure::LABELS)->multiple(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([self::stageActions(), self::readinessAction(), self::copyAction(), EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No departures yet')
            ->emptyStateDescription('A departure is one dated run of this package.');
    }
}
