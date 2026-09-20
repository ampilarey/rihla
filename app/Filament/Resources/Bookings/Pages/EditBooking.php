<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\SeatHold;
use App\Services\Booking\SeatAllocator;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * One booking, and the four things staff can do to it.
 *
 * Every action goes through the domain rather than writing a column:
 * {@see Booking::transitionTo()} refuses an illegal move and records who and
 * why, and {@see SeatAllocator} is the only thing that touches a departure's
 * seat counters. A "set status" dropdown would bypass both, and the
 * departure would oversell the first time two people used it at once.
 */
class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->confirmAction(),
            $this->extendHoldAction(),
            $this->cancelAction(),
        ];
    }

    private function booking(): Booking
    {
        /** @var Booking */
        return $this->getRecord();
    }

    /**
     * Money has not been taken — nothing can take it yet — so this is a
     * person saying the payment arrived. The label says so rather than
     * implying the system checked.
     */
    private function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm (payment received)')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => in_array($this->booking()->status, [Booking::DRAFT, Booking::HELD], true))
            ->requiresConfirmation()
            ->modalDescription('This moves the seats from held to confirmed. Only do it once the money is actually in.')
            ->schema([
                Textarea::make('reason')
                    ->label('Note for the record')
                    ->placeholder('Bank transfer received, slip on WhatsApp')
                    ->rows(2),
            ])
            ->action(function (array $data): void {
                $booking = $this->booking();
                $hold = $booking->seatHolds()->latest('id')->first();

                try {
                    // Confirm the seats first. If they cannot be had — the
                    // hold lapsed and somebody else took them — the booking
                    // must not be marked confirmed, because that would be a
                    // promise the departure cannot keep.
                    if ($hold instanceof SeatHold) {
                        app(SeatAllocator::class)->confirm($hold);
                    }

                    $booking->transitionTo(Booking::CONFIRMED, $data['reason'] ?: null);
                } catch (NoSeatsAvailable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Those seats are gone')
                        ->body($e->getMessage().' The booking has not been confirmed.')
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title('Booking confirmed')->send();

                $this->refreshFormData(['status']);
            });
    }

    /**
     * Fifteen minutes is right for somebody at a checkout and far too short
     * for somebody who has rung up to ask a question. Staff can give them
     * longer — deliberately as a fresh hold with a new expiry rather than an
     * open-ended one, so seats can never be held indefinitely by accident.
     */
    private function extendHoldAction(): Action
    {
        return Action::make('extendHold')
            ->label('Extend the hold')
            ->icon('heroicon-o-clock')
            ->visible(fn (): bool => in_array($this->booking()->status, [Booking::DRAFT, Booking::HELD], true))
            ->schema([
                Textarea::make('reason')->label('Why')->rows(2)->placeholder('Waiting on the bank transfer'),
            ])
            ->action(function (array $data): void {
                $booking = $this->booking();
                $allocator = app(SeatAllocator::class);
                $minutes = (int) config('booking.holds.extension_minutes', 1440);

                $existing = $booking->seatHolds()->latest('id')->first();

                try {
                    $hold = $allocator->hold(
                        $booking->departure,
                        $booking->seats,
                        $booking,
                        now()->addMinutes($minutes),
                    );
                } catch (NoSeatsAvailable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot extend')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                // The new hold is in place before the old one goes back, so
                // the seats are never briefly available to somebody else.
                if ($existing instanceof SeatHold && $existing->isNot($hold)) {
                    $allocator->release($existing, SeatHold::CANCELLED);
                }

                $booking->transitions()->create([
                    'from_status' => $booking->status,
                    'to_status' => $booking->status,
                    'user_id' => auth()->id(),
                    'reason' => 'Hold extended. '.($data['reason'] ?: ''),
                    'created_at' => now(),
                ]);

                Notification::make()->success()->title('Hold extended')->send();
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel booking')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => in_array(
                $this->booking()->status,
                [Booking::DRAFT, Booking::HELD, Booking::CONFIRMED],
                true,
            ))
            ->requiresConfirmation()
            ->modalDescription('The seats go back on the departure. The booking is kept, with a record of who cancelled it and why.')
            ->schema([
                Textarea::make('reason')->label('Why')->required()->rows(2),
            ])
            ->action(function (array $data): void {
                $booking = $this->booking();
                $allocator = app(SeatAllocator::class);

                // Confirmed seats and held seats live in different counters,
                // and subtracting one from the other would leave the
                // departure permanently, invisibly full.
                if ($booking->status === Booking::CONFIRMED) {
                    $allocator->releaseConfirmed($booking);
                } else {
                    $hold = $booking->seatHolds()->whereNull('released_at')->latest('id')->first();

                    if ($hold instanceof SeatHold) {
                        $allocator->release($hold, SeatHold::CANCELLED);
                    }
                }

                $booking->transitionTo(Booking::CANCELLED, $data['reason']);

                Notification::make()->success()->title('Booking cancelled')->send();

                $this->refreshFormData(['status']);
            });
    }
}
