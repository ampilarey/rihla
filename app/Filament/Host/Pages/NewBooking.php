<?php

namespace App\Filament\Host\Pages;

use App\Exceptions\DeskRefusal;
use App\Exceptions\NotSoldToAudience;
use App\Exceptions\RoomNotAvailable;
use App\Filament\Host\Resources\Bookings\BookingResource;
use App\Filament\Pages\NewStay;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Stays\Availability;
use App\Services\Stays\DirectBooking;
use App\Support\Audience;
use App\Support\HostContext;
use App\Support\HostRole;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * A booking the host takes themselves — §16.6.
 *
 * The calendar's free cells link here with the date and room filled in.
 * Everything goes through {@see DirectBooking}, which is the marketplace's
 * own path with the host's yes already given.
 *
 * @property-read Schema $form
 */
class NewBooking extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'New booking';

    protected static ?string $title = 'New booking';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'new-booking';

    protected string $view = 'filament.pages.services';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::BOOKINGS);
    }

    public function mount(): void
    {
        // From a free cell on the calendar: that night, that room.
        $unit = request()->integer('unit') ? $this->units()->find(request()->integer('unit')) : null;
        $from = request()->date('check_in');

        $this->form->fill([
            'room_type_id' => $unit?->room_type_id,
            'unit_id' => $unit?->getKey(),
            'check_in' => $from?->toDateString(),
            // The shortest stay the listing takes, so the first thing the
            // form says is not a refusal of its own default.
            'check_out' => $from?->addDays(max(1, (int) $unit?->property?->min_nights))->toDateString(),
            'citizenship' => 'visitor',
            'adults' => 2,
            'children' => 0,
            'source' => 'phone',
            'deposit_method' => Payment::CASH,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The room')
                    ->columns(2)
                    ->schema([
                        Select::make('room_type_id')
                            ->label('Room')
                            ->required()
                            ->options(fn (): array => $this->rooms()->get()
                                ->mapWithKeys(fn (RoomType $room): array => [
                                    $room->getKey() => $room->property->getTranslation('name', 'en').' — '.$room->getTranslation('name', 'en'),
                                ])
                                ->all())
                            ->live()
                            // A room chosen for another kind is let go; one of this kind stays.
                            ->afterStateUpdated(function (callable $set, Get $get, $state): void {
                                if (filled($get('unit_id')) && (int) $this->units()->find($get('unit_id'))?->room_type_id !== (int) $state) {
                                    $set('unit_id', null);
                                }
                            }),

                        Select::make('unit_id')
                            ->label('Put them in')
                            ->placeholder('Decide at check-in')
                            ->options(fn (Get $get): array => blank($get('room_type_id')) ? [] : $this->units()
                                ->where('room_type_id', $get('room_type_id'))
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->pluck('label', 'id')
                                ->all()),

                        DatePicker::make('check_in')->label('Check in')->required()->native(false)->live(),
                        DatePicker::make('check_out')->label('Check out')->required()->after('check_in')->native(false)->live(),

                        TextInput::make('adults')->numeric()->integer()->minValue(1)->maxValue(30)->required(),
                        TextInput::make('children')->numeric()->integer()->minValue(0)->maxValue(30),

                        TextEntry::make('quote')
                            ->label('Your rate comes to')
                            ->state(fn (Get $get): string => $this->quoteLine($get)),

                        TextInput::make('agreed_total')
                            ->label('Agreed price, if different (whole units)')
                            ->numeric()->integer()->minValue(0)
                            ->helperText('Your own guest, your own price. The rate it replaced is kept on the booking.'),
                    ]),

                Section::make('The guest')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('phone')->tel()->required()->maxLength(40),
                        TextInput::make('email')->email()->maxLength(255),
                        Select::make('citizenship')
                            ->label('Nationality')
                            ->options(['visitor' => 'A visitor', 'maldivian' => 'A Maldivian citizen'])
                            ->required()
                            ->live()
                            ->helperText('Decides which of your rates applies.'),
                    ]),

                Section::make('How it came in')
                    ->columns(2)
                    ->schema([
                        Select::make('source')->label('They reached you by')->options(NewStay::SOURCES)->required(),
                        TextInput::make('deposit')
                            ->label('Deposit taken now (whole units)')
                            ->numeric()->integer()->minValue(0),
                        Select::make('deposit_method')
                            ->label('Paid by')
                            ->options([
                                Payment::CASH => 'Cash',
                                Payment::BANK_TRANSFER => 'Bank transfer',
                                Payment::CARD => 'Card, on your own machine',
                            ]),
                        Textarea::make('notes')->label('Notes')->rows(3)->maxLength(1000)->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Book it')->submit('save')];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();
        $room = $this->rooms()->findOrFail($data['room_type_id']);
        $unit = filled($data['unit_id'] ?? null) ? $this->units()->findOrFail($data['unit_id']) : null;
        $currency = (string) $room->property->currency;
        $user = auth()->user();

        try {
            $stay = app(DirectBooking::class)->take(
                $this->host(),
                $room,
                CarbonImmutable::parse($data['check_in']),
                CarbonImmutable::parse($data['check_out']),
                ['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null],
                adults: (int) $data['adults'],
                children: (int) ($data['children'] ?? 0),
                audience: $data['citizenship'] === 'maldivian' ? Audience::LOCAL : Audience::TOURIST,
                source: $data['source'],
                agreedTotal: filled($data['agreed_total'] ?? null) ? Money::ofMajor((int) $data['agreed_total'], $currency) : null,
                depositTaken: filled($data['deposit'] ?? null) ? Money::ofMajor((int) $data['deposit'], $currency) : null,
                depositMethod: (string) ($data['deposit_method'] ?? Payment::CASH),
                unit: $unit,
                notes: $data['notes'] ?? null,
                by: $user instanceof User ? $user : null,
            );
        } catch (RoomNotAvailable $refusal) {
            Notification::make()->danger()->title('Not free for those dates')->body($refusal->getMessage())->send();

            return;
        } catch (NotSoldToAudience) {
            Notification::make()->danger()->title('No local rate for those nights')->body('Add a local rate to this room, or book them at your visitor rate with an agreed price.')->send();

            return;
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title('Not booked')->body($refusal->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Booked — '.$stay->reference)->send();

        $this->redirect(BookingResource::getUrl('view', ['record' => $stay]));
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }

    /** @return Builder<RoomType> */
    private function rooms()
    {
        return RoomType::query()
            ->with('property')
            ->whereHas('property', fn ($query) => $query->where('partner_id', $this->host()->getKey()))
            ->orderBy('property_id')
            ->orderBy('sort_order');
    }

    /** @return Builder<PropertyUnit> */
    private function units()
    {
        return PropertyUnit::query()
            ->whereHas('property', fn ($query) => $query->where('partner_id', $this->host()->getKey()));
    }

    private function quoteLine(Get $get): string
    {
        $room = filled($get('room_type_id')) ? $this->rooms()->find($get('room_type_id')) : null;

        if ($room === null || blank($get('check_in')) || blank($get('check_out'))) {
            return 'Choose a room and dates.';
        }

        try {
            $in = CarbonImmutable::parse($get('check_in'));
            $out = CarbonImmutable::parse($get('check_out'));

            if ($out->lessThanOrEqualTo($in)) {
                return 'Check-out has to be after check-in.';
            }

            $audience = $get('citizenship') === 'maldivian' ? Audience::LOCAL : Audience::TOURIST;
            $quote = app(Availability::class)->quote($room, $in, $out, $audience);

            try {
                app(Availability::class)->assertAvailable($room, $in, $out);
                $why = '';
            } catch (RoomNotAvailable $refusal) {
                // The real reason — a minimum stay is not a clash.
                $why = ' — '.$refusal->getMessage();
            }

            return sprintf('%s for %d night(s)%s', $quote->total()->format(), $quote->nights(), $why);
        } catch (NotSoldToAudience) {
            return 'No local rate for every one of those nights.';
        } catch (\Throwable) {
            return 'Choose a room and dates.';
        }
    }
}
