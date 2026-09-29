<?php

namespace App\Filament\Host\Resources\Bookings\Pages;

use App\Exceptions\DeskRefusal;
use App\Exceptions\RoomNotAvailable;
use App\Filament\Host\Resources\Bookings\BookingResource;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\PropertyUnit;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Models\StayGuest;
use App\Models\StayMessage;
use App\Models\User;
use App\Services\Stays\StayBill;
use App\Services\Stays\StayBooking;
use App\Services\Stays\StayDesk;
use App\Services\Stays\StayMessages;
use App\Support\HostContext;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One booking at a host's desk — §16.6, §16.10.
 *
 * Every action goes through {@see StayDesk} or {@see StayBooking}, the same
 * services `/staff` uses, so a rule cannot be kept on one panel and missed
 * on the other. Each is behind the policy's host branch, which also checks
 * the stay is this host's.
 */
class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // §16.11: the host opening the booking is the host reading it.
        app(StayMessages::class)->markRead($this->stay(), StayMessage::HOST);
    }

    public function getSubheading(): ?string
    {
        return BookingResource::statusLabel($this->stay()->status)
            .' · '.BookingResource::sourceLabel($this->stay()->source)
            .($this->mismatch() !== null ? ' · '.$this->mismatch() : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->accept(),
            $this->decline(),
            $this->checkIn(),
            $this->checkOut(),
            $this->message(),
            $this->recordPayment(),
            $this->printBill(),
            ActionGroup::make([
                $this->addCharge(),
                $this->refundHere(),
                $this->cancel(),
            ])->label('More')->button(),
        ];
    }

    private function accept(): Action
    {
        return Action::make('accept')
            ->label('Accept')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (): bool => $this->stay()->status === Stay::REQUESTED)
            ->authorize(fn (): bool => $this->may())
            ->requiresConfirmation()
            ->modalHeading('Accept this booking?')
            ->modalDescription('The room is taken off your calendar now, and the guest is asked for the deposit. Nobody else can book these nights while it is held.')
            ->action(function (): void {
                try {
                    app(StayBooking::class)->confirmWithPartner($this->stay());
                } catch (RoomNotAvailable $refusal) {
                    Notification::make()->title('Those nights have gone')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Accepted')->body('Waiting for the guest\'s deposit.')->success()->send();
                $this->refreshStay();
            });
    }

    private function decline(): Action
    {
        return Action::make('decline')
            ->label('Decline')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->stay()->status === Stay::REQUESTED)
            ->authorize(fn (): bool => $this->may())
            ->schema([
                Textarea::make('reason')
                    ->label('What should the guest be told?')
                    ->rows(3)
                    ->required()
                    ->helperText('They read this. "We are full that week" is kinder than silence.'),
            ])
            ->action(function (array $data): void {
                app(StayBooking::class)->decline($this->stay(), $data['reason']);

                Notification::make()->title('Declined')->success()->send();
                $this->refreshStay();
            });
    }

    private function checkIn(): Action
    {
        return Action::make('checkIn')
            ->label('Check in')
            ->icon('heroicon-o-key')
            ->color('primary')
            ->visible(fn (): bool => $this->stay()->status === Stay::CONFIRMED)
            ->authorize(fn (): bool => $this->may())
            ->modalHeading('Check in')
            ->modalDescription('The guest register is required by law. Add everybody staying, and mark who is answerable for the room.')
            ->fillForm(fn (): array => [
                'guests' => [[
                    'full_name' => $this->stay()->customer?->name,
                    'id_type' => StayGuest::PASSPORT,
                    'is_lead' => true,
                ]],
            ])
            ->schema([
                Select::make('unit_id')
                    ->label('Room')
                    ->options(fn (): array => PropertyUnit::query()
                        ->where('room_type_id', $this->stay()->room_type_id)
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn (PropertyUnit $unit): array => [
                            $unit->getKey() => $unit->label.($unit->housekeeping === PropertyUnit::DIRTY ? ' — not yet cleaned' : ''),
                        ])
                        ->all())
                    ->helperText('Optional. A room marked "not yet cleaned" can still be chosen — it may just have been done.'),
                Repeater::make('guests')
                    ->label('Who is staying')
                    ->minItems(1)
                    ->maxItems(fn (): int => max(1, $this->stay()->adults + $this->stay()->children))
                    ->columns(3)
                    ->schema([
                        TextInput::make('full_name')->label('Full name')->required()->maxLength(160),
                        TextInput::make('nationality')->maxLength(60),
                        DatePicker::make('date_of_birth')->label('Born'),
                        Select::make('id_type')->label('Document')->options([
                            StayGuest::PASSPORT => 'Passport',
                            StayGuest::NATIONAL_ID => 'National ID',
                        ]),
                        TextInput::make('id_number')->label('Document number')->maxLength(40),
                        Toggle::make('is_lead')->label('Lead guest'),
                    ]),
            ])
            ->action(function (array $data): void {
                $unit = filled($data['unit_id'] ?? null) ? PropertyUnit::find($data['unit_id']) : null;

                try {
                    app(StayDesk::class)->checkIn($this->stay(), array_values($data['guests'] ?? []), $unit);
                } catch (DeskRefusal $refusal) {
                    Notification::make()->title('Not checked in')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                $this->refreshStay();
                $mismatch = $this->mismatch();

                Notification::make()
                    ->title('Checked in')
                    ->body($mismatch !== null ? $mismatch.' You may add an adjustment to the bill, or leave it.' : null)
                    ->{$mismatch !== null ? 'warning' : 'success'}()
                    ->send();
            });
    }

    private function checkOut(): Action
    {
        return Action::make('checkOut')
            ->label('Check out')
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->visible(fn (): bool => $this->stay()->status === Stay::CHECKED_IN)
            ->authorize(fn (): bool => $this->may())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'Balance at the desk: '.StayBill::for($this->stay())->balance()->format().'. The room goes to housekeeping.')
            ->action(function (): void {
                try {
                    app(StayDesk::class)->checkOut($this->stay());
                } catch (DeskRefusal $refusal) {
                    Notification::make()->title('Not checked out')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Checked out')->success()->send();
                $this->refreshStay();
            });
    }

    /** Write to the guest — §16.11. They read it on their stay page, and Rihla sees it too. */
    private function message(): Action
    {
        return Action::make('message')
            ->label('Message the guest')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('gray')
            ->authorize(fn (): bool => $this->may())
            ->modalDescription('The guest reads this on their stay page. Rihla can read it too.')
            ->schema([
                Textarea::make('body')->label('Message')->required()->rows(4)->maxLength(StayMessage::MAX_LENGTH),
            ])
            ->action(function (array $data): void {
                try {
                    app(StayMessages::class)->post($this->stay(), StayMessage::HOST, $data['body'], $this->user());
                } catch (DeskRefusal $refusal) {
                    Notification::make()->title('Not sent')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Sent')->success()->send();
                $this->refreshStay();
            });
    }

    private function addCharge(): Action
    {
        return Action::make('addCharge')
            ->label('Add to the bill')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => in_array($this->stay()->status, [Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED], true))
            ->authorize(fn (): bool => $this->may())
            ->schema([
                Select::make('kind')->options([
                    StayCharge::EXTRA => 'An extra (a meal, a transfer, an excursion)',
                    StayCharge::DISCOUNT => 'A discount',
                    StayCharge::ADJUSTMENT => 'An adjustment',
                ])->required()->default(StayCharge::EXTRA),
                TextInput::make('description')->required()->maxLength(160),
                TextInput::make('quantity')->numeric()->integer()->minValue(1)->default(1)->required(),
                TextInput::make('each')
                    ->label(fn (): string => 'Price each ('.$this->stay()->currency.')')
                    ->numeric()->integer()->minValue(0)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(StayDesk::class)->addCharge(
                        $this->stay(),
                        $data['kind'],
                        $data['description'],
                        (int) $data['quantity'],
                        Money::ofMajor((int) $data['each'], $this->stay()->currency),
                        $this->user(),
                    );
                } catch (DeskRefusal $refusal) {
                    Notification::make()->title('Not added')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Added to the bill')->success()->send();
                $this->refreshStay();
            });
    }

    private function recordPayment(): Action
    {
        return $this->paymentAction('recordPayment', refund: false)
            ->label('Payment received here')
            ->icon('heroicon-o-banknotes')
            ->color('success');
    }

    private function refundHere(): Action
    {
        return $this->paymentAction('refundHere', refund: true)
            ->label('Refund given here')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (): bool => $this->stay()->paidToHost()->minor > 0);
    }

    /**
     * Money that changed hands at the property — §16.9.
     *
     * Never the booking deposit: that is paid online to Rihla, and a host
     * recording cash here does not confirm a stay waiting for it.
     */
    private function paymentAction(string $name, bool $refund): Action
    {
        return Action::make($name)
            ->visible(fn (): bool => in_array($this->stay()->status, [Stay::HELD, Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED], true))
            ->authorize(fn (): bool => $this->may())
            ->modalDescription($refund
                ? 'Money you gave back to the guest, across your own counter.'
                : 'Money the guest paid you at the property. The online booking deposit is paid to Rihla and is not recorded here.')
            ->schema([
                TextInput::make('amount')
                    ->label(fn (): string => 'Amount ('.$this->stay()->currency.')')
                    ->numeric()->integer()->minValue(1)->required(),
                Select::make('method')->options([
                    Payment::CASH => 'Cash',
                    Payment::BANK_TRANSFER => 'Bank transfer',
                    Payment::CARD => 'Card, on your own machine',
                ])->required()->default(Payment::CASH),
                TextInput::make('note')->maxLength(200),
            ])
            ->action(function (array $data) use ($refund): void {
                try {
                    app(StayDesk::class)->recordHostPayment(
                        $this->stay(),
                        $this->host(),
                        Money::ofMajor((int) $data['amount'], $this->stay()->currency),
                        $data['method'],
                        $data['note'] ?? null,
                        $this->user(),
                        $refund,
                    );
                } catch (DeskRefusal $refusal) {
                    Notification::make()->title('Not recorded')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title($refund ? 'Refund recorded' : 'Payment recorded')->success()->send();
                $this->refreshStay();
            });
    }

    private function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancel booking')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (): bool => $this->stay()->isOccupying())
            ->authorize(fn (): bool => $this->may())
            ->modalDescription('The nights go back on your calendar. Any online deposit is Rihla\'s to refund under the terms the guest agreed to — tell Rihla why.')
            ->schema([
                Textarea::make('reason')->label('Why? The guest reads this.')->rows(3)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(StayDesk::class)->cancel($this->stay(), $data['reason']);
                } catch (DeskRefusal $refusal) {
                    Notification::make()->title('Not cancelled')->body($refusal->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Cancelled')->success()->send();
                $this->refreshStay();
            });
    }

    private function printBill(): Action
    {
        return Action::make('printBill')
            ->label('Print bill')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->visible(fn (): bool => in_array($this->stay()->status, [Stay::CONFIRMED, Stay::CHECKED_IN, Stay::COMPLETED], true))
            ->authorize(fn (): bool => $this->may())
            ->action(function (): StreamedResponse {
                $pdf = StayBill::for($this->stay())->pdf();

                return response()->streamDownload(fn () => print ($pdf), 'bill-'.$this->stay()->reference.'.pdf', [
                    'Content-Type' => 'application/pdf',
                ]);
            });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function stay(): Stay
    {
        /** @var Stay */
        return $this->getRecord();
    }

    private function refreshStay(): void
    {
        $this->getRecord()->refresh();
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** The policy's host branch: this member's role here, and this host's stay. */
    private function may(): bool
    {
        return $this->user()?->can('update', $this->stay()) === true;
    }

    private function mismatch(): ?string
    {
        return $this->stay()->status === Stay::CHECKED_IN || $this->stay()->status === Stay::COMPLETED
            ? app(StayDesk::class)->audienceMismatch($this->stay())
            : null;
    }
}
