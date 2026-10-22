<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Card;
use App\Models\DepartureHotel;
use App\Models\Document;
use App\Models\RoomAssignment;
use App\Services\Operations\Buses;
use App\Services\Operations\Cards;
use App\Services\Operations\Families;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Point the phone at a card or a tag — Hijaz's Scan page, brought across.
 *
 * The QR holds a token; this page turns it back into the traveller and
 * shows what the person at the coach door needs: who, which bus, which
 * room, and a button to tick them on board. Chrome on Android reads the QR
 * through the browser's own detector; any other phone uses its camera app,
 * because the QR is a link to this page.
 */
class Scan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Scan';

    protected static ?string $title = 'Scan a card or tag';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.scan';

    public string $code = '';

    public ?int $cardId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('card.scan') === true;
    }

    public function mount(): void
    {
        $token = (string) request()->query('t', '');

        if ($token !== '') {
            $this->code = $token;
            $this->lookup();
        }
    }

    public function lookup(): void
    {
        $card = app(Cards::class)->resolve($this->code);

        if ($card === null) {
            $this->cardId = null;

            Notification::make()
                ->danger()
                ->title('No card matches that')
                ->body('Type the serial printed under the QR, like RIH-C-000012. A reissued card\'s old serial no longer works.')
                ->send();

            return;
        }

        $this->cardId = $card->getKey();
    }

    public function clear(): void
    {
        $this->code = '';
        $this->cardId = null;
    }

    public function checkIn(): void
    {
        $member = $this->getMember();

        if ($member === null || auth()->user()?->can('bus.checkIn') !== true || $member->busGroup === null) {
            return;
        }

        app(Buses::class)->toggleCheckIn($member);

        Notification::make()
            ->success()
            ->title($member->isCheckedIn() ? $member->traveller->full_name.' is on '.$member->busGroup->name : 'Boarding undone')
            ->send();
    }

    public function getCard(): ?Card
    {
        return $this->cardId === null ? null : Card::query()->find($this->cardId);
    }

    public function getMember(): ?BookingTraveller
    {
        $member = $this->getCard()?->bookingTraveller;

        return $member?->load(['traveller', 'booking.customer', 'booking.departure.package', 'booking.departure.hotels', 'busGroup']);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $member = $this->getMember();
        $booking = $member?->booking;
        $user = auth()->user();
        // A lost child's tag is scanned by somebody who needs one number:
        // the child's own, else the family head's, else the booker's.
        $family = $member === null ? null : app(Families::class)->of($member);

        return [
            'card' => $this->getCard(),
            'member' => $member,
            'booking' => $booking,
            'family' => $family,
            'phone' => $member === null ? null : $family?->phoneFor($member),
            'phoneSource' => match ($member === null ? null : $family?->phoneSourceFor($member)) {
                'own' => 'their own',
                'head' => 'family head',
                'booker' => 'booker',
                default => null,
            },
            'rooms' => $member === null ? [] : $member->booking->departure->hotels
                ->map(fn (DepartureHotel $hotel): array => ['city' => $hotel->cityLabel(), 'room' => self::roomIn($hotel, $member)])
                ->all(),
            'passport' => $member === null ? null : self::passportState($member),
            'balance' => $booking instanceof Booking ? ($booking->balance()->minor > 0 ? $booking->balance()->format().' due' : 'Paid') : null,
            'canCheckIn' => $user?->can('bus.checkIn') === true,
            'canSeeMoney' => $user?->can('payment.viewAny') === true,
            'canOpenBooking' => $user?->can('booking.view') === true,
        ];
    }

    private static function roomIn(DepartureHotel $hotel, BookingTraveller $member): ?string
    {
        return RoomAssignment::query()
            ->where('traveller_id', $member->traveller_id)
            ->whereHas('room', fn ($query) => $query->where('departure_hotel_id', $hotel->getKey()))
            ->with('room')
            ->first()
            ?->room
            ?->label;
    }

    private static function passportState(BookingTraveller $member): string
    {
        $passport = Document::query()
            ->where('traveller_id', $member->traveller_id)
            ->where('type', Document::PASSPORT)
            ->latest('id')
            ->first();

        return match (true) {
            $passport === null || $passport->currentVersion() === null => 'no copy',
            $passport->status === Document::VERIFIED => 'copy verified',
            $passport->status === Document::REJECTED => 'copy rejected',
            default => 'copy to check',
        };
    }
}
