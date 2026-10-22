<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Exceptions\IllegalBookingTransition;
use App\Exceptions\NoSeatsAvailable;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\Concerns\HasInvoiceAndDiscountActions;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\NusukPermit;
use App\Models\Payment;
use App\Models\PortalAccess;
use App\Models\SeatHold;
use App\Models\Traveller;
use App\Services\Booking\SeatAllocator;
use App\Services\Nusuk\PermitDesk;
use App\Services\Operations\Families;
use App\Services\Payments\Gateways;
use App\Services\Payments\SlipVault;
use App\Services\Portal\Gatekeeper;
use App\Services\Visa\VisaDesk;
use App\Support\Money;
use App\Support\Rates;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One booking, and the things staff can do to it.
 *
 * Every action goes through the domain rather than writing a column:
 * {@see Booking::transitionTo()} refuses an illegal move and records who and
 * why, and {@see SeatAllocator} is the only thing that touches a departure's
 * seat counters. A "set status" dropdown would bypass both, and the
 * departure would oversell the first time two people used it at once.
 */
class EditBooking extends EditRecord
{
    use HasInvoiceAndDiscountActions;

    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->recordPaymentAction(),
            $this->invoiceAction(),
            $this->discountAction(),
            $this->ticketsAction(),
            $this->familyAction(),
            $this->portalLinkAction(),
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
     * The booking as a document, for sending or printing.
     *
     * Generated on demand rather than stored: an invoice is a rendering of
     * the booking as it stands, and a saved PDF is a second copy of the
     * truth that drifts the moment a line changes. What is immutable is
     * already in `booking_lines` and `payments`.
     */
    /**
     * Hand the customer a way into the Pilgrim Portal (§6.1).
     *
     * Staff send it themselves, over WhatsApp, because that is how this
     * operator already talks to every customer and because there is nothing
     * to send it with automatically — no SMTP, no SMS gateway. A password
     * login would have no password reset, and a login somebody can be locked
     * out of for ever is worse than no login.
     *
     * **The link is shown once.** The token is stored hashed, so this is the
     * only moment it exists in readable form. Losing it means issuing
     * another, which is deliberate: a system that can show you the link
     * again can be made to show it to somebody else.
     */
    private function portalLinkAction(): Action
    {
        return Action::make('portalLink')
            ->label('Portal link')
            ->icon('heroicon-o-link')
            ->visible(fn (): bool => auth()->user()?->can('booking.update') === true)
            ->modalHeading('A link to this booking')
            ->modalDescription(fn (): string => sprintf(
                'Good for %d days. Send it on WhatsApp and it opens their booking, what they still owe, '
                .'and what documents we are waiting for. It does not let anybody download a passport scan.'
                .'%s',
                (int) config('portal.link_days', 30),
                $this->liveLinkCount() > 0
                    ? ' There '.($this->liveLinkCount() === 1 ? 'is 1 link' : 'are '.$this->liveLinkCount().' links')
                        .' already working for this booking.'
                    : '',
            ))
            ->schema([
                Textarea::make('link')
                    ->label('Copy this now — it is not shown again')
                    ->rows(3)
                    ->readOnly()
                    // Filled when the modal opens, which is also when the
                    // link is minted: a link generated by rendering a table
                    // row would put a working credential in the page for
                    // every booking on screen.
                    ->default(fn (): string => $this->mintLink())
                    ->helperText('The token is stored hashed, so nobody — including us — can read it back afterwards.'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done')
            ->extraModalFooterActions([
                Action::make('revokeLinks')
                    ->label('Cancel every link for this booking')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->modalDescription('Every link issued for this booking stops working immediately, including the one above. Use this when one went to the wrong number.')
                    ->action(function (): void {
                        $killed = app(Gatekeeper::class)->revokeAllFor($this->booking());

                        Notification::make()
                            ->warning()
                            ->title(trans_choice('{1}:count link cancelled|[2,*]:count links cancelled', $killed, ['count' => $killed]))
                            ->send();
                    }),
            ]);
    }

    private function mintLink(): string
    {
        return route('portal.enter', [
            'locale' => app()->getLocale(),
            'token' => app(Gatekeeper::class)->issue($this->booking()),
        ]);
    }

    private function liveLinkCount(): int
    {
        return PortalAccess::where('booking_id', $this->booking()->getKey())->live()->count();
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

                // Batch C from Hijaz: money may arrive in dollars or riyals.
                // The booking is credited in its own currency at the rate
                // typed here, which the rate table fills in for the day.
                Select::make('received_currency')
                    ->label('Received in')
                    ->options(array_combine(Rates::currencies(), Rates::currencies()))
                    ->default(fn (): string => $this->booking()->currency)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->live()
                    // The rate box follows the currency chosen: the table's
                    // rate for the day, or empty where the table has none.
                    // A default() on the rate box itself is evaluated once,
                    // before this select has a value, and sat at 1.
                    ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('exchange_rate', Rates::rate((string) ($state ?: $this->booking()->currency)))),

                TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    // Whole units: "285.50" was silently 285 (site audit).
                    ->integer()
                    ->required()
                    ->live(onBlur: true)
                    ->prefix(fn (Get $get): string => (string) ($get('received_currency') ?: $this->booking()->currency))
                    ->helperText(fn (): string => 'Whole units. The balance is '
                        .$this->booking()->balance()->format().'.'),

                TextInput::make('exchange_rate')
                    ->label(fn (Get $get): string => '1 '.$get('received_currency').' = … '.$this->booking()->currency)
                    ->numeric()
                    ->step('0.0001')
                    ->minValue(0.000001)
                    ->visible(fn (Get $get): bool => $get('received_currency') !== null && $get('received_currency') !== $this->booking()->currency)
                    ->required(fn (Get $get): bool => $get('received_currency') !== null && $get('received_currency') !== $this->booking()->currency)
                    ->live(onBlur: true)
                    ->helperText(fn (Get $get): string => self::creditWords($this->booking()->currency, $get('received_currency'), $get('amount'), $get('exchange_rate'))),

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
                $receivedIn = strtoupper((string) ($data['received_currency'] ?? $booking->currency));
                $received = Money::ofMajor((int) $data['amount'], $receivedIn);
                $details = [
                    'paid_at' => $data['paid_at'] ?: null,
                    'payer_name' => $data['payer_name'] ?: null,
                    'payer_reference' => $data['payer_reference'] ?? null,
                    'notes' => $data['notes'] ?: null,
                ];

                if ($receivedIn === $booking->currency) {
                    $credit = $received;
                } else {
                    try {
                        $rate = (float) ($data['exchange_rate'] ?? 0);
                        $credit = Rates::toBase($received, $rate > 0 ? $rate : null);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Not recorded')->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    $details += [
                        'received_currency' => $receivedIn,
                        'received_minor' => $received->minor,
                        'exchange_rate' => $rate > 0 ? $rate : Rates::rate($receivedIn),
                    ];
                }

                $payment = app(Gateways::class)->for($data['method'])->start($booking, $credit, $details);

                if (($data['slip'] ?? null) !== null) {
                    app(SlipVault::class)->attach($payment, $data['slip']);
                }

                Notification::make()
                    ->success()
                    ->title('Recorded '.$payment->money()->format().($payment->receivedWords() ? ' — received '.$payment->receivedWords() : ''))
                    // Said plainly, because the difference between "recorded"
                    // and "received" is the whole point of the separation.
                    ->body('Nothing has been added to the paid total yet. Finance checks it on the payments screen.')
                    ->send();
            });
    }

    /** "= MVR 10,023 credited to the booking", as the form is filled in. */
    private static function creditWords(string $bookingCurrency, mixed $receivedIn, mixed $amount, mixed $rate): string
    {
        $receivedIn = strtoupper((string) ($receivedIn ?: $bookingCurrency));

        if ($receivedIn === $bookingCurrency) {
            return '';
        }

        if (! is_numeric($rate) || (float) $rate <= 0) {
            return 'No rate is known for '.$receivedIn.' today. Type the one the bank gave, or set it under Exchange rates.';
        }

        if (! is_numeric($amount) || (int) $amount < 1) {
            return 'Each '.$receivedIn.' is credited as '.Money::ofMinor((int) round((float) $rate * 100), $bookingCurrency)->format().'.';
        }

        return '= '.Rates::toBase(Money::ofMajor((int) $amount, $receivedIn), (float) $rate)->format().' credited to the booking.';
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

    /**
     * The airline's two facts about each traveller, typed once the tickets
     * are issued. One small form for the whole party rather than an edit
     * screen per traveller: the tickets arrive together, on one e-mail
     * from the airline, and are typed together.
     */
    private function ticketsAction(): Action
    {
        return Action::make('tickets')
            ->label('Tickets & seats')
            ->icon('heroicon-o-ticket')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('booking.update') === true
                && $this->booking()->travellers->isNotEmpty())
            ->modalHeading('Tickets and seats')
            ->modalDescription('Printed on the manifest the airline is handed. Leave a box empty for a ticket not issued yet.')
            ->fillForm(fn (): array => [
                'lines' => $this->booking()->travellers
                    ->mapWithKeys(fn (BookingTraveller $line): array => [
                        $line->getKey() => [
                            'ticket_number' => $line->ticket_number,
                            'seat_number' => $line->seat_number,
                        ],
                    ])
                    ->all(),
            ])
            ->schema(fn (): array => $this->booking()->travellers
                ->map(fn (BookingTraveller $line): Section => Section::make($line->traveller->full_name)
                    ->columns(2)
                    ->schema([
                        TextInput::make('lines.'.$line->getKey().'.ticket_number')
                            ->label('Ticket number')
                            ->maxLength(30),
                        TextInput::make('lines.'.$line->getKey().'.seat_number')
                            ->label('Seat')
                            ->maxLength(8)
                            ->placeholder('12A'),
                    ]))
                ->all())
            ->action(function (array $data): void {
                $booking = $this->booking();

                foreach ($booking->travellers as $line) {
                    $typed = $data['lines'][$line->getKey()] ?? [];

                    $line->update([
                        'ticket_number' => trim((string) ($typed['ticket_number'] ?? '')) ?: null,
                        'seat_number' => strtoupper(trim((string) ($typed['seat_number'] ?? ''))) ?: null,
                    ]);
                }

                Notification::make()->success()->title('Tickets saved')->send();
            });
    }

    /**
     * The family, batch C from Hijaz: who heads it, how each traveller is
     * related to the person who booked, and a phone that works on the
     * journey. One small form for the whole party, like the tickets.
     *
     * The head may be anybody in the family, including somebody on the
     * booker's other booking for this departure; the relationships and
     * phones are this booking's people, and the other booking's are set
     * from that booking.
     */
    private function familyAction(): Action
    {
        return Action::make('family')
            ->label('Family')
            ->icon('heroicon-o-user-group')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('booking.update') === true
                && $this->booking()->travellers->isNotEmpty())
            ->modalHeading('The family')
            ->modalDescription('The head holds the family\'s documents and is the one we call, so give them a phone that works on the journey. Each relationship is to the person who booked.')
            ->fillForm(fn (): array => [
                'head' => app(Families::class)->forBooking($this->booking())?->head->getKey(),
                'people' => $this->booking()->travellers
                    ->mapWithKeys(fn (BookingTraveller $line): array => [
                        $line->getKey() => [
                            'relationship' => $line->traveller->relationship,
                            'phone' => $line->traveller->phone,
                        ],
                    ])
                    ->all(),
            ])
            ->schema(fn (): array => [
                Select::make('head')
                    ->label('Head of the family')
                    ->required()
                    ->native(false)
                    ->options(fn (): array => $this->familyOptions())
                    ->helperText('Holds the passports, tickets and papers for everybody, and is who the office and the tour leader ring.'),

                ...$this->booking()->travellers
                    ->map(fn (BookingTraveller $line): Section => Section::make($line->traveller->full_name)
                        ->columns(2)
                        ->schema([
                            Select::make('people.'.$line->getKey().'.relationship')
                                ->label('To the person who booked')
                                ->native(false)
                                ->options(Traveller::RELATIONSHIP_LABELS),
                            TextInput::make('people.'.$line->getKey().'.phone')
                                ->label('Phone on the journey')
                                ->tel()
                                ->maxLength(40),
                        ]))
                    ->all(),
            ])
            ->action(function (array $data): void {
                $booking = $this->booking();

                foreach ($booking->travellers as $line) {
                    $typed = $data['people'][$line->getKey()] ?? [];
                    $relationship = $typed['relationship'] ?? null;
                    $current = $line->traveller->relationship;

                    $line->traveller->update([
                        // The column is free-form and the form offers a list:
                        // a value the list does not have is kept, not blanked
                        // because the select could not show it.
                        'relationship' => match (true) {
                            in_array($relationship, Traveller::RELATIONSHIPS, true) => $relationship,
                            $current !== null && ! in_array($current, Traveller::RELATIONSHIPS, true) => $current,
                            default => null,
                        },
                        'phone' => trim((string) ($typed['phone'] ?? '')) ?: null,
                    ]);
                }

                $families = app(Families::class);
                $head = $families->forBooking($booking)?->members
                    ->first(fn (BookingTraveller $member): bool => (int) $member->getKey() === (int) ($data['head'] ?? 0));

                if ($head instanceof BookingTraveller) {
                    $families->makeHead($head);
                }

                Notification::make()->success()->title('Family saved')->send();
            });
    }

    /**
     * Everybody in this booking's family, for the head select: this
     * booking's people by name, anybody on another of the booker's
     * bookings with the booking they are on.
     *
     * @return array<int, string>
     */
    private function familyOptions(): array
    {
        $booking = $this->booking();
        $family = app(Families::class)->forBooking($booking);

        if ($family === null) {
            return [];
        }

        return $family->members
            ->mapWithKeys(fn (BookingTraveller $member): array => [
                $member->getKey() => $member->traveller->full_name
                    .((int) $member->booking_id === (int) $booking->getKey() ? '' : ' · on '.$member->booking->reference),
            ])
            ->all();
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
            ->visible(fn (): bool => in_array($this->booking()->status, [Booking::DRAFT, Booking::HELD], true)
                && $this->mayConfirm())
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

                try {
                    // One transaction for the seats and the status, with the
                    // status re-read inside it (site audit). Before, the seats
                    // were committed first and the status checked afterwards
                    // against a page loaded minutes ago — so a booking the
                    // sweep had expired meanwhile took the seats again and
                    // then threw, leaving confirmed seats nobody held.
                    DB::transaction(function () use ($booking, $data): void {
                        $booking->refresh();

                        if (! in_array($booking->status, [Booking::DRAFT, Booking::HELD], true)) {
                            throw IllegalBookingTransition::from($booking, Booking::CONFIRMED);
                        }

                        $allocator = app(SeatAllocator::class);
                        $hold = $booking->seatHolds()->latest('id')->first();

                        // A booking with no hold at all (a double-submitted
                        // checkout) asks for its seats now, under the lock,
                        // rather than being confirmed without any.
                        if (! $hold instanceof SeatHold) {
                            $hold = $allocator->hold($booking->departure, $booking->seats, $booking, now()->addMinute());
                        }

                        // A draft passes through held on its way: that is the
                        // only road the status machine knows.
                        if ($booking->status === Booking::DRAFT) {
                            $booking->transitionTo(Booking::HELD);
                        }

                        // Confirm the seats first. If they cannot be had — the
                        // hold lapsed and somebody else took them — the booking
                        // must not be marked confirmed, because that would be a
                        // promise the departure cannot keep.
                        $allocator->confirm($hold);

                        $booking->transitionTo(Booking::CONFIRMED, $data['reason'] ?: null);
                    });
                } catch (IllegalBookingTransition $e) {
                    Notification::make()->warning()->title('This booking has moved on')->body($e->getMessage())->send();
                    $this->refreshFormData(['status']);

                    return;
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
                    // A hold that exists gets more time, lapsed or not (see
                    // SeatAllocator::extend); only a booking with none takes
                    // a fresh one.
                    $hold = $existing instanceof SeatHold && $existing->confirmed_at === null
                        ? $allocator->extend($existing, now()->addMinutes($minutes))
                        : $allocator->hold($booking->departure, $booking->seats, $booking, now()->addMinutes($minutes));
                } catch (NoSeatsAvailable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Cannot extend')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $booking->refresh();

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

                try {
                    // Seats and status in one transaction, the status re-read
                    // first (site audit): two cancellations at once must give
                    // the seats back once.
                    DB::transaction(function () use ($booking, $allocator, $data): void {
                        $booking->refresh();

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
                    });
                } catch (IllegalBookingTransition $e) {
                    Notification::make()->warning()->title('This booking has moved on')->body($e->getMessage())->send();
                    $this->refreshFormData(['status']);

                    return;
                }

                Notification::make()->success()->title('Booking cancelled')->send();

                $this->refreshFormData(['status']);
            });
    }

    /**
     * "Confirm (payment received)" is the money decision, so it takes the
     * permission for it — or money Finance has already recorded against
     * this booking, at least the deposit (site audit). Booking Staff are
     * deliberately denied `payment.reconcile`; this button used to let
     * them make the same decision at the booking level.
     */
    private function mayConfirm(): bool
    {
        $booking = $this->booking();

        if (auth()->user()?->can('payment.reconcile') === true) {
            return true;
        }

        return $booking->paid_minor > 0 && $booking->paid_minor >= $booking->deposit_minor;
    }
}
