<?php

namespace Modules\Transport\Filament\Resources\TransportRequests\Pages;

use App\Exceptions\DeskRefusal;
use App\Models\Island;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Get;
use Modules\Transport\Filament\Resources\TransportRequests\TransportRequestResource;
use Modules\Transport\Models\TransportRequest;
use Modules\Transport\Services\TransportRequests;

class ManageTransportRequests extends ManageRecords
{
    protected static string $resource = TransportRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Somebody on the phone or at the counter: the same request the
            // public form makes, so it is quoted and moved on the same way.
            Action::make('new')
                ->label('New request')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('transport.book') === true)
                ->schema([
                    Select::make('kind')->options(TransportRequest::KINDS)->required()->default(TransportRequest::CARGO)->live()->native(false),
                    Select::make('origin_island_id')->label('From')->required()->searchable()
                        ->options(fn (): array => Island::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('destination_island_id')->label('To')->required()->searchable()->different('origin_island_id')
                        ->options(fn (): array => Island::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                    DatePicker::make('wanted_on')->label('On')->required()->minDate(today()),
                    Textarea::make('cargo_description')->label('What the cargo is')->rows(2)->maxLength(2000)
                        ->visible(fn (Get $get): bool => $get('kind') === TransportRequest::CARGO)->required(fn (Get $get): bool => $get('kind') === TransportRequest::CARGO),
                    TextInput::make('cargo_weight_kg')->label('Weight (kg)')->integer()->minValue(1)
                        ->visible(fn (Get $get): bool => $get('kind') === TransportRequest::CARGO),
                    TextInput::make('passengers')->label('People')->integer()->minValue(1)->maxValue(500)
                        ->visible(fn (Get $get): bool => $get('kind') === TransportRequest::CHARTER)->required(fn (Get $get): bool => $get('kind') === TransportRequest::CHARTER),
                    TextInput::make('contact_name')->label('Their name')->required()->maxLength(120),
                    TextInput::make('contact_phone')->label('Their phone')->tel()->required()->maxLength(30),
                    Textarea::make('notes')->rows(2)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    try {
                        $made = app(TransportRequests::class)->submit([
                            'kind' => (string) $data['kind'],
                            'origin_island_id' => (int) $data['origin_island_id'],
                            'destination_island_id' => (int) $data['destination_island_id'],
                            'wanted_on' => (string) $data['wanted_on'],
                            'contact_name' => (string) $data['contact_name'],
                            'contact_phone' => (string) $data['contact_phone'],
                            'passengers' => isset($data['passengers']) ? (int) $data['passengers'] : null,
                            'cargo_description' => $data['cargo_description'] ?? null,
                            'cargo_weight_kg' => isset($data['cargo_weight_kg']) ? (int) $data['cargo_weight_kg'] : null,
                            'notes' => $data['notes'] ?? null,
                        ]);
                        Notification::make()->success()->title('Request '.$made->reference.' taken')->body('Quote it from the list.')->send();
                    } catch (DeskRefusal $refusal) {
                        Notification::make()->danger()->title($refusal->getMessage())->send();
                    }
                }),
        ];
    }
}
