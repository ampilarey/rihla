<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Schemas\BookingDeskForm;
use App\Models\Customer;
use App\Models\Departure;
use App\Services\Booking\BookingDesk;
use App\Support\Audience;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A booking taken at the desk — a walk-in, a phone call.
 *
 * Its own form rather than the resource's: the booking screen reads a
 * booking back, and this one makes it. The record is never inserted by
 * Filament; the desk opens it, taking the seats under the departure's
 * row lock and pricing the party, in one transaction that a full
 * departure rolls back whole.
 */
class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'New booking';

    public function form(Schema $schema): Schema
    {
        return BookingDeskForm::configure($schema);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $departure = Departure::query()->with(['priceTiers', 'package'])->findOrFail($data['departure_id']);
        $customer = Customer::query()->findOrFail($data['customer_id']);

        $party = array_values(array_map(
            fn (array $member): array => [
                'traveller_id' => (int) ($member['traveller_id'] ?? 0),
                'occupancy' => (string) ($member['occupancy'] ?? ''),
            ],
            is_array($data['party'] ?? null) ? $data['party'] : [],
        ));

        $audience = is_string($data['audience'] ?? null) ? $data['audience'] : Audience::LOCAL;

        try {
            return app(BookingDesk::class)->open($departure, $customer, $party, $audience, $data['notes'] ?? null);
        } catch (NoSeatsAvailable|InvalidArgumentException $e) {
            Notification::make()
                ->danger()
                ->title('Not booked')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            // What halt() does, thrown here so the signature can see that
            // nothing is returned: the page catches it and stays put.
            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Booking opened. The seats are held for '.self::holdWords().'; confirm it once the money is in.';
    }

    private static function holdWords(): string
    {
        $minutes = (int) config('booking.holds.extension_minutes', 1440);

        return $minutes % 1440 === 0 ? intdiv($minutes, 1440).' day'.($minutes === 1440 ? '' : 's') : $minutes.' minutes';
    }
}
