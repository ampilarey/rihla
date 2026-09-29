<?php

namespace App\Filament\Pages;

use App\Exceptions\NotSoldToAudience;
use App\Exceptions\RoomNotAvailable;
use App\Filament\Resources\Stays\StayResource;
use App\Models\Customer;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\Stays\Availability;
use App\Services\Stays\StayBooking;
use App\Support\Audience;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * The office takes a booking by phone — §16.7, §16 Phase 13.4.
 *
 * The same path a guest's online booking takes: {@see StayBooking::request()}
 * with its availability check, its frozen quote and — when the host has
 * already said yes — the row lock that takes the dates. The only
 * differences are that a person at Rihla typed it and says how the guest
 * reached them. A stay taken this way is **not** a marketplace booking
 * and carries no commission (ADR 0008 decision 2): the office entered it.
 *
 * Phase 14's host panel reuses this flow for a host's own phone bookings.
 *
 * @property-read Schema $form
 */
class NewStay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'New stay';

    protected static ?string $title = 'Take a booking';

    protected static UnitEnum|string|null $navigationGroup = 'Stays';

    protected static ?string $slug = 'new-stay';

    protected string $view = 'filament.pages.services';

    /** How the guest reached us — `stays.source`. */
    public const SOURCES = [
        'phone' => 'Phone call',
        'whatsapp' => 'WhatsApp',
        'walk_in' => 'Walked into the office',
        'other_site' => 'Another booking site',
        'other' => 'Something else',
    ];

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('stay.book') === true;
    }

    public function mount(): void
    {
        $this->form->fill([
            'audience' => Audience::TOURIST,
            'adults' => 2,
            'children' => 0,
            'source' => 'phone',
            'hold_now' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The room')
                    ->columns(2)
                    ->schema([
                        Select::make('property_id')
                            ->label('Property')
                            ->required()
                            ->searchable()
                            ->options(fn (): array => Property::published()
                                ->where('approval', Property::APPROVED)
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (Property $property): array => [$property->id => $property->getTranslation('name', 'en')])
                                ->all())
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('room_type_id', null)),

                        Select::make('room_type_id')
                            ->label('Room')
                            ->required()
                            ->options(fn (Get $get): array => $get('property_id') === null ? [] : RoomType::where('property_id', $get('property_id'))
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (RoomType $room): array => [$room->id => $room->getTranslation('name', 'en').' (sleeps '.$room->sleeps.')'])
                                ->all())
                            ->live(),

                        DatePicker::make('check_in')->label('Check in')->required()->native(false)->live(),
                        DatePicker::make('check_out')->label('Check out')->required()->after('check_in')->native(false)->live(),

                        TextInput::make('adults')->numeric()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('children')->numeric()->minValue(0)->maxValue(30),

                        Select::make('audience')
                            ->label('Priced as')
                            ->options([Audience::TOURIST => 'A visitor (tourist price)', Audience::LOCAL => 'A Maldivian (local price)'])
                            ->required()
                            ->live(),

                        // The price the stay will be frozen at, before it is.
                        TextEntry::make('quote')
                            ->label('Comes to')
                            ->state(fn (Get $get): string => $this->quoteLine($get)),
                    ]),

                Section::make('The guest')
                    ->description('Choose a customer we already have, or type a new one.')
                    ->columns(2)
                    ->schema([
                        Select::make('customer_id')
                            ->label('Existing customer')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->limit(20)
                                ->pluck('name', 'id')
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Customer::find($value)?->name)
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('name')->maxLength(255)
                            ->required(fn (Get $get): bool => blank($get('customer_id')))
                            ->hidden(fn (Get $get): bool => filled($get('customer_id'))),
                        TextInput::make('phone')->tel()->maxLength(40)
                            ->required(fn (Get $get): bool => blank($get('customer_id')))
                            ->hidden(fn (Get $get): bool => filled($get('customer_id'))),
                        TextInput::make('email')->email()->maxLength(255)
                            ->hidden(fn (Get $get): bool => filled($get('customer_id'))),
                    ]),

                Section::make('How it came in')
                    ->columns(2)
                    ->schema([
                        Select::make('source')->label('They reached us by')->options(self::SOURCES)->required(),
                        Toggle::make('hold_now')
                            ->label('The host has already said yes — take the dates now')
                            ->helperText('Holds the room and starts the deposit clock, as the Confirm button on the board does.')
                            ->visible(fn (): bool => auth()->user()?->can('stay.confirm') === true),
                        Textarea::make('special_requests')->label('Anything the host should know')->rows(3)->maxLength(1000)->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Take the booking')->submit('save')];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();
        $room = RoomType::with('property')->findOrFail($data['room_type_id']);

        abort_unless((int) $room->property_id === (int) $data['property_id'], 422);

        $holdNow = (bool) ($data['hold_now'] ?? false) && auth()->user()?->can('stay.confirm') === true;

        try {
            $stay = DB::transaction(function () use ($data, $room, $holdNow): Stay {
                $customer = filled($data['customer_id'] ?? null)
                    ? Customer::findOrFail($data['customer_id'])
                    : Customer::create([
                        'name' => $data['name'],
                        'phone' => $data['phone'],
                        'email' => $data['email'] ?? null,
                    ]);

                $booking = app(StayBooking::class);

                $stay = $booking->request(
                    $customer,
                    $room,
                    CarbonImmutable::parse($data['check_in']),
                    CarbonImmutable::parse($data['check_out']),
                    adults: (int) $data['adults'],
                    children: (int) ($data['children'] ?? 0),
                    details: [
                        'special_requests' => $data['special_requests'] ?? null,
                        'source' => $data['source'],
                        'created_via' => Stay::VIA_STAFF,
                        'created_by' => auth()->id(),
                    ],
                    audience: $data['audience'],
                );

                // An instant-book room is already held by request().
                return $holdNow && $stay->status === Stay::REQUESTED
                    ? $booking->confirmWithPartner($stay)
                    : $stay;
            });
        } catch (RoomNotAvailable $refusal) {
            Notification::make()->danger()->title('Not free for those dates')->body($refusal->getMessage())->send();

            return;
        } catch (NotSoldToAudience) {
            Notification::make()->danger()->title('No local price for those dates')->body('That room is not sold at local prices for every night asked for.')->send();

            return;
        }

        Notification::make()->success()->title('Booked — '.$stay->reference)->send();

        $this->redirect(StayResource::getUrl('view', ['record' => $stay]));
    }

    /** The quote for what is on the form, or why there is none yet. */
    private function quoteLine(Get $get): string
    {
        $room = filled($get('room_type_id')) ? RoomType::find($get('room_type_id')) : null;

        if ($room === null || blank($get('check_in')) || blank($get('check_out'))) {
            return 'Choose a room and dates.';
        }

        try {
            $in = CarbonImmutable::parse($get('check_in'));
            $out = CarbonImmutable::parse($get('check_out'));

            if ($out->lessThanOrEqualTo($in)) {
                return 'Check-out has to be after check-in.';
            }

            $quote = app(Availability::class)->quote($room, $in, $out, (string) ($get('audience') ?: Audience::TOURIST));
            $free = app(Availability::class)->isAvailable($room, $in, $out);

            return sprintf('%s for %d night(s)%s', $quote->total()->format(), $quote->nights(), $free ? '' : ' — not free for those dates');
        } catch (NotSoldToAudience) {
            return 'No local price for every one of those nights.';
        } catch (\Throwable) {
            return 'Choose a room and dates.';
        }
    }
}
