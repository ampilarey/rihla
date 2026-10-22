<?php

namespace App\Filament\Resources\Bookings\Schemas;

use App\Filament\Support\DepartureOptions;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\PriceTier;
use App\Models\Traveller;
use App\Services\Booking\BookingDesk;
use App\Support\Audience;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Taking a booking at the desk: which departure, who is paying, who is
 * travelling and in what room. The price of each seat is shown as it is
 * chosen, from the departure's own list by room and by age on the
 * departure day — the same arithmetic as the checkout, so a phone booking
 * costs what the website would have charged.
 *
 * A customer or a traveller who is not on file yet is added from the
 * form, without leaving it: the person is on the phone.
 */
class BookingDeskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Departure and customer')
                ->columns(2)
                ->schema([
                    Select::make('departure_id')
                        ->label('Departure')
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->live()
                        ->options(fn (): array => self::departures())
                        ->helperText('Only departures that are still to leave and have seats entered.'),

                    Select::make('audience')
                        ->label('Price list')
                        ->options([
                            Audience::LOCAL => 'Maldivians (MVR)',
                            Audience::TOURIST => 'Visitors ('.strtoupper((string) config('marketplace.currencies.tourist', 'USD')).')',
                        ])
                        ->default(Audience::LOCAL)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        // Only a departure that prices visitors offers the
                        // choice; every other booking is a local one.
                        ->visible(fn (Get $get): bool => self::departure($get('departure_id'))
                            ?->priceTiers->contains('audience', Audience::TOURIST) === true),

                    Select::make('customer_id')
                        ->label('Customer')
                        ->required()
                        ->native(false)
                        ->searchable()
                        ->live()
                        ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orderBy('name')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Customer $customer): array => [$customer->getKey() => self::customerLabel($customer)])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($customer = Customer::query()->find($value)) !== null
                            ? self::customerLabel($customer)
                            : null)
                        ->createOptionForm([
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('phone')->required()->maxLength(40)->tel(),
                            TextInput::make('email')->email()->maxLength(255),
                        ])
                        ->createOptionUsing(fn (array $data): int => Customer::query()->create($data)->getKey())
                        ->createOptionModalHeading('New customer')
                        ->helperText('Who is paying. Search by name or phone, or add them.'),
                ]),

            Section::make('Who is travelling')
                ->description('Everyone on the booking, with the room they share. The price comes from the departure\'s list by room and by age on the departure day; a child is priced as an adult where the departure has no child price, exactly as the website does. The first person is the head of the family: they hold its documents and are the one we call, and the booking\'s Family button changes it later.')
                ->schema([
                    Repeater::make('party')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('traveller_id')
                                ->label('Traveller')
                                ->required()
                                ->native(false)
                                ->live()
                                ->distinct()
                                ->options(fn (Get $get): array => self::travellersOf($get('../../customer_id')))
                                ->createOptionForm([
                                    TextInput::make('full_name')->label('Full name, as in the passport')->required()->maxLength(255),
                                    DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->maxDate(now()),
                                    Select::make('gender')->options(array_combine(Traveller::GENDERS, array_map('ucfirst', Traveller::GENDERS)))->native(false),
                                    TextInput::make('passport_number')->label('Passport number')->maxLength(40),
                                    DatePicker::make('passport_expiry')->label('Passport expires')->native(false),
                                    Select::make('relationship')->label('To the person who booked')->options(Traveller::RELATIONSHIP_LABELS)->native(false),
                                    TextInput::make('phone')->label('Phone on the journey')->tel()->maxLength(40),
                                ])
                                ->createOptionUsing(function (array $data, Get $get): ?int {
                                    $customerId = $get('../../customer_id');

                                    if (! filled($customerId)) {
                                        return null;
                                    }

                                    return Traveller::query()->create($data + ['customer_id' => (int) $customerId])->getKey();
                                })
                                ->createOptionModalHeading('New traveller')
                                ->disabled(fn (Get $get): bool => ! filled($get('../../customer_id')))
                                ->columnSpan(2),

                            Select::make('occupancy')
                                ->label('Room')
                                ->required()
                                ->native(false)
                                ->live()
                                ->options(fn (Get $get): array => self::rooms($get('../../departure_id'), $get('../../audience'))),

                            Placeholder::make('price')
                                ->label('Price')
                                ->content(fn (Get $get): string => self::preview($get)),
                        ])
                        ->columns(4)
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Add a traveller')
                        ->reorderable(false),
                ]),

            Textarea::make('notes')
                ->rows(3)
                ->columnSpanFull()
                ->placeholder('What was said on the phone: wheelchair, prefers a lower floor, paying in two parts.'),
        ]);
    }

    /** @return array<int, string> */
    private static function departures(): array
    {
        return Departure::query()
            ->with('package')
            ->upcoming()
            ->where('capacity_total', '>', 0)
            ->orderBy('date_start')
            ->get()
            ->mapWithKeys(fn (Departure $departure): array => [
                $departure->getKey() => DepartureOptions::label($departure).' · '.$departure->seats_remaining.' of '.$departure->capacity_total.' seats free',
            ])
            ->all();
    }

    private static function departure(mixed $id): ?Departure
    {
        return filled($id) ? Departure::query()->with('priceTiers')->find($id) : null;
    }

    private static function customerLabel(Customer $customer): string
    {
        return $customer->name.($customer->phone ? ' · '.$customer->phone : '');
    }

    /** @return array<int, string> */
    private static function travellersOf(mixed $customerId): array
    {
        if (! filled($customerId)) {
            return [];
        }

        return Traveller::query()
            ->where('customer_id', $customerId)
            ->orderBy('full_name')
            ->get()
            ->mapWithKeys(fn (Traveller $traveller): array => [
                $traveller->getKey() => $traveller->full_name.($traveller->date_of_birth ? ' · '.$traveller->date_of_birth->age : ''),
            ])
            ->all();
    }

    /**
     * The rooms this departure prices on this list, or every room while
     * no departure is chosen yet.
     *
     * @return array<string, string>
     */
    private static function rooms(mixed $departureId, mixed $audience): array
    {
        $departure = self::departure($departureId);
        $list = Audience::isValid(is_string($audience) ? $audience : null) ? $audience : Audience::LOCAL;

        $offered = $departure?->occupanciesOffered($list) ?? PriceTier::OCCUPANCIES;

        return array_combine($offered, array_map('ucfirst', $offered));
    }

    private static function preview(Get $get): string
    {
        $departure = self::departure($get('../../departure_id'));
        $traveller = filled($get('traveller_id')) ? Traveller::query()->find($get('traveller_id')) : null;
        $occupancy = $get('occupancy');

        if ($departure === null || $traveller === null || ! filled($occupancy)) {
            return '—';
        }

        $audience = $get('../../audience');
        $audience = Audience::isValid(is_string($audience) ? $audience : null) ? $audience : Audience::LOCAL;
        $paxType = PriceTier::paxTypeForAge($traveller->ageOn($departure->date_start));
        $tier = app(BookingDesk::class)->tierFor($departure, (string) $occupancy, $paxType, $audience);

        return $tier === null
            ? 'no price for that room'
            : $tier->money()->format().' · '.$paxType;
    }
}
