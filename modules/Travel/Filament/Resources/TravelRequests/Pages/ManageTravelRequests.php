<?php

namespace Modules\Travel\Filament\Resources\TravelRequests\Pages;

use App\Exceptions\DeskRefusal;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Modules\Travel\Filament\Resources\TravelRequests\TravelRequestResource;
use Modules\Travel\Services\TravelRequests;

class ManageTravelRequests extends ManageRecords
{
    protected static string $resource = TravelRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Somebody on the phone or at the counter: the same request "Plan
            // my trip" makes, with its own page and key for the customer.
            Action::make('new')
                ->label('New request')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('travel.quote') === true)
                ->schema([
                    CheckboxList::make('wants')->label('What they need')->required()->columns(3)
                        ->options(['flight' => 'Flight', 'hotel' => 'Hotel', 'visa' => 'Visa']),
                    TextInput::make('origin')->label('From')->maxLength(80)->default('Malé'),
                    TextInput::make('destination')->label('Where to')->required()->maxLength(120),
                    DatePicker::make('depart_on')->label('Leaving on')->required()->minDate(today()),
                    DatePicker::make('return_on')->label('Back on')->afterOrEqual('depart_on'),
                    TextInput::make('adults')->integer()->minValue(1)->maxValue(50)->required()->default(1),
                    TextInput::make('children')->integer()->minValue(0)->maxValue(50)->default(0),
                    TextInput::make('contact_name')->label('Their name')->required()->maxLength(120),
                    TextInput::make('contact_phone')->label('Their phone')->tel()->required()->maxLength(30),
                    TextInput::make('contact_email')->label('Their e-mail')->email()->maxLength(190)
                        ->helperText('Options and updates are e-mailed to it as well as sent by WhatsApp.'),
                    Textarea::make('notes')->rows(2)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $wants = (array) ($data['wants'] ?? []);

                    try {
                        $made = app(TravelRequests::class)->submit([
                            'wants_flight' => in_array('flight', $wants, true),
                            'wants_hotel' => in_array('hotel', $wants, true),
                            'wants_visa' => in_array('visa', $wants, true),
                            'origin' => $data['origin'] ?? null,
                            'destination' => (string) $data['destination'],
                            'depart_on' => (string) $data['depart_on'],
                            'return_on' => $data['return_on'] ?? null,
                            'adults' => (int) $data['adults'],
                            'children' => (int) ($data['children'] ?? 0),
                            'contact_name' => (string) $data['contact_name'],
                            'contact_phone' => (string) $data['contact_phone'],
                            'contact_email' => $data['contact_email'] ?? null,
                            'notes' => $data['notes'] ?? null,
                        ]);
                        Notification::make()->success()->title('Request '.$made->reference.' taken')->body('Add options from the list.')->send();
                    } catch (DeskRefusal $refusal) {
                        Notification::make()->danger()->title($refusal->getMessage())->send();
                    }
                }),
        ];
    }
}
