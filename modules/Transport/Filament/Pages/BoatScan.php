<?php

namespace Modules\Transport\Filament\Pages;

use App\Exceptions\DeskRefusal;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatTicket;
use Modules\Transport\Services\TransportDesk;
use UnitEnum;

/**
 * Boarding a boat — docs/TRANSPORT_PLAN.md (T3). The QR on a passenger's
 * ticket is a link to this page, so any phone's camera opens it; Chrome on
 * Android can also read it in the page. It shows the passenger, their
 * booking, and a button to tick them on board.
 */
class BoatScan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Board a boat';

    protected static ?string $title = 'Board passengers';

    protected static UnitEnum|string|null $navigationGroup = 'Transport';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'boat-scan';

    protected string $view = 'filament.transport.boat-scan';

    public string $code = '';

    /** The server found this ticket; the browser never chooses it. */
    #[Locked]
    public ?int $ticketId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('transport.board') === true;
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
        $ticket = app(TransportDesk::class)->resolve($this->code);
        $this->ticketId = $ticket?->getKey();

        if ($ticket === null) {
            Notification::make()->danger()->title('No ticket matches that')
                ->body('Scan the QR on the passenger\'s ticket page again, or open the booking from the Bookings screen.')->send();
        }
    }

    public function clear(): void
    {
        $this->code = '';
        $this->ticketId = null;
    }

    public function board(): void
    {
        $ticket = $this->getTicket();

        if ($ticket === null) {
            return;
        }

        try {
            app(TransportDesk::class)->board($ticket, auth()->user());
            Notification::make()->success()->title($ticket->full_name.' is on board')->send();
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title($refusal->getMessage())->send();
        }
    }

    public function unboard(): void
    {
        $ticket = $this->getTicket();

        if ($ticket !== null) {
            app(TransportDesk::class)->unboard($ticket);
            Notification::make()->title('Boarding undone')->send();
        }
    }

    public function getTicket(): ?BoatTicket
    {
        return $this->ticketId === null ? null
            : BoatTicket::query()->with(['booking.sailing.route.origin', 'booking.sailing.route.destination', 'booking.sailing.vessel', 'booking.tickets'])->find($this->ticketId);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $ticket = $this->getTicket();
        $booking = $ticket?->booking;

        return [
            'ticket' => $ticket,
            'booking' => $booking,
            'sailing' => $booking?->sailing,
            'statusLabel' => $booking === null ? null : (BoatBooking::STATUSES[$booking->status] ?? $booking->status),
        ];
    }
}
