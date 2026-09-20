<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\NusukPermit;
use App\Models\Payment;
use App\Models\SeatHold;
use App\Services\Booking\SeatAllocator;
use App\Services\Nusuk\PermitDesk;
use App\Services\Payments\Gateways;
use App\Services\Payments\SlipVault;
use App\Services\Visa\VisaDesk;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * One booking, and the six things staff can do to it.
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
            $this->recordPaymentAction(),
            $this->confirmAction(),
            $this->openVisasAction(),
            $this->openPermitsAction(),
            $this->extendHoldAction(),
            $this->cancelAction(),
        ];
    }

    /**
     * The booking's way into the visa workflow (§5.4a).
     *
     * One application per traveller, because a visa is granted to a person
     * and not to a party. Idempotent: pressing it twice does not queue two
     * submissions to a government for the same traveller.
     *
     * Opens visas and **only** visas. Nusuk permits are a separate
     * authorisation with separate failure modes [R-4], and a button that
     * started both would invite exactly the shortcut that strands a pilgrim.
     */
    private function openVisasAction(): Action
    {
        return Action::make('openVisas')
            ->label('Open visa applications')
            ->icon('heroicon-o-identification')
            ->visible(fn (): bool => auth()->user()?->can('visa.create') === true
                && $this->booking()->travellers()->exists())
            ->schema([
                Select::make('visa_type')
                    ->label('Visa type')
                    ->options(config('visa.types'))
                    ->helperText('The permitted list is configuration — what Saudi Arabia accepts is their rule, not this application\'s.'),
            ])
            ->action(function (array $data): void {
                $opened = app(VisaDesk::class)->openForBooking(
                    $this->booking(),
                    $data['visa_type'] ?: null,
                );

                Notification::make()
                    ->success()
                    ->title(trans_choice(
                        '{1}:count visa application open|[2,*]:count visa applications open',
                        $opened->count(),
                        ['count' => $opened->count()],
                    ))
                    ->send();
            });
    }

    /**
     * The booking's way into the Nusuk workflow (§5.4b).
     *
     * A second button beside the visa one, never the same button [R-4].
     * They are different authorisations from different systems, and one
     * control that started both would teach the person pressing it that
     * they are one thing — which is the mental shortcut that strands a
     * pilgrim holding a valid visa outside the Mataf.
     *
     * Umrah permits only. Rawdah slots are asked for one at a time from the
     * permits screen: not everybody wants one, the slots are scarce, and
     * requesting one for a party that did not ask spends a slot another
     * pilgrim needed.
     */
    private function openPermitsAction(): Action
    {
        return Action::make('openPermits')
            ->label('Open Umrah permits')
            ->icon('heroicon-o-ticket')
            ->visible(fn (): bool => auth()->user()?->can('permit.create') === true
                && $this->booking()->travellers()->exists())
            ->requiresConfirmation()
            // Said before the button is pressed rather than after, because
            // the gate bites at *request* time: opening the records
            // succeeds, and the person would find out a screen later.
            ->modalDescription(fn (): string => $this->permitGateWarning()
                ?? 'One Umrah permit per traveller. Pressing this twice does not put two requests into a Saudi system for one person.')
            ->action(function (): void {
                $opened = app(PermitDesk::class)->openForBooking($this->booking());

                Notification::make()
                    ->success()
                    ->title(trans_choice(
                        '{1}:count Umrah permit open|[2,*]:count Umrah permits open',
                        $opened->count(),
                        ['count' => $opened->count()],
                    ))
                    ->send();
            });
    }

    /**
     * Record money against this booking (§5.3).
     *
     * Recording is not reconciling. Anybody taking the booking can enter
     * what a customer says they have sent; saying the money has arrived is
     * a separate act by somebody holding `payment.reconcile`, on the
     * payments screen. Collapsing the two is how an unchecked slip becomes a
     * confirmed booking and a seat nobody paid for.
     *
     * Only the methods that can actually be used are offered. Card is absent
     * while BML has no merchant account, rather than present and throwing.
     */
    private function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Record a payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (): bool => auth()->user()?->can('payment.create') === true
                && app(Gateways::class)->available() !== [])
            ->schema([
                Select::make('method')
                    ->label('How it came')
                    ->options(fn (): array => app(Gateways::class)->options())
                    ->default(Payment::BANK_TRANSFER)
                    ->required()
                    ->live(),

                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required()
                    ->prefix(fn (): string => $this->booking()->currency)
                    ->helperText(fn (): string => 'Whole rufiyaa. The balance is '
                        .$this->booking()->balance()->format().'.'),

                DatePicker::make('paid_at')
                    ->label('When they say it was sent')
                    ->native(false)
                    ->maxDate(now()),

                TextInput::make('payer_name')
                    ->label('Sent by')
                    ->maxLength(255)
                    ->helperText('Often not one of the travellers — a father, an employer, a relative abroad.'),

                TextInput::make('payer_reference')
                    ->label('Their reference')
                    ->maxLength(80)
                    ->visible(fn (callable $get): bool => $get('method') === Payment::BANK_TRANSFER)
                    ->helperText('Whatever the sender typed into the box. Kept as they wrote it — it is what matches a statement.'),

                FileUpload::make('slip')
                    ->label('Transfer slip')
                    ->disk(config('payments.slips.disk'))
                    ->visibility('private')
                    ->acceptedFileTypes(config('payments.slips.mime_types'))
                    ->maxSize((int) config('payments.slips.max_kilobytes'))
                    // The vault stores it, not Filament: it checksums the
                    // file, keeps any superseded one and records the event.
                    ->storeFiles(false)
                    ->visible(fn (callable $get): bool => $get('method') === Payment::BANK_TRANSFER),

                Textarea::make('notes')->label('Note')->rows(2),
            ])
            ->action(function (array $data): void {
                $booking = $this->booking();

                $payment = app(Gateways::class)->for($data['method'])->start(
                    $booking,
                    Money::ofMajor((int) $data['amount'], $booking->currency),
                    [
                        'paid_at' => $data['paid_at'] ?: null,
                        'payer_name' => $data['payer_name'] ?: null,
                        'payer_reference' => $data['payer_reference'] ?? null,
                        'notes' => $data['notes'] ?: null,
                    ],
                );

                if (($data['slip'] ?? null) !== null) {
                    app(SlipVault::class)->attach($payment, $data['slip']);
                }

                Notification::make()
                    ->success()
                    ->title('Recorded '.$payment->money()->format())
                    // Said plainly, because the difference between "recorded"
                    // and "received" is the whole point of the separation.
                    ->body('Nothing has been added to the paid total yet. Finance checks it on the payments screen.')
                    ->send();
            });
    }

    /**
     * What Nusuk still wants recorded on this departure, in a sentence, or
     * null when there is nothing to warn about.
     */
    private function permitGateWarning(): ?string
    {
        // No null guard on the relation: `bookings.departure_id` is NOT NULL
        // and restricts deletes, so a booking without a departure is not a
        // state this table can hold. The first draft guarded it and static
        // analysis would have called it dead, the same way it did for
        // Departure's date columns.
        $missing = NusukPermit::missingPrerequisites($this->booking()->departure);

        if ($missing === []) {
            return null;
        }

        return 'The permits will open, but none can be requested until this departure\'s '
            .implode(' and ', $missing)
            .' is recorded in Nusuk. That is set on the departure.';
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
