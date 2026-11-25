<?php

namespace Modules\Transport\Filament\Resources\Sailings\Pages;

use App\Exceptions\DeskRefusal;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Modules\Transport\Filament\Resources\Sailings\SailingResource;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Services\TransportDesk;
use Modules\Transport\Support\OperatorContext;

class ManageSailings extends ManageRecords
{
    protected static string $resource = SailingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A run of sailings from a timetable: the usual way in.
            Action::make('timetable')
                ->label('Add a timetable')
                ->icon('heroicon-o-calendar-days')
                ->visible(fn (): bool => auth()->user()?->can('transport.manage') === true)
                ->modalDescription('Creates a sailing for each chosen day and time in the range. One that already exists is left alone.')
                ->schema([
                    Select::make('boat_route_id')->label('Route')->options(fn (): array => SailingResource::routeOptions())->required(),
                    Select::make('vessel_id')->label('Boat')->options(fn (): array => SailingResource::vesselOptions())->required(),
                    DatePicker::make('from')->required()->default(now()->toDateString()),
                    DatePicker::make('to')->required()->default(now()->addDays(6)->toDateString()),
                    CheckboxList::make('weekdays')->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'])->columns(7)->required()->default([1, 2, 3, 4, 5, 6, 7]),
                    TagsInput::make('times')->placeholder('07:30')->required()->helperText('Each time it leaves, like 07:30 and 15:00.'),
                    TextInput::make('seats')->label('Seats on sale')->integer()->minValue(1)->helperText('Leave empty to sell every seat on the boat.'),
                ])
                ->action(function (array $data): void {
                    try {
                        $created = app(TransportDesk::class)->schedule(
                            BoatRoute::query()->when(OperatorContext::id(), fn ($q, int $id) => $q->where('partner_id', $id))->findOrFail($data['boat_route_id']),
                            Vessel::query()->when(OperatorContext::id(), fn ($q, int $id) => $q->where('partner_id', $id))->findOrFail($data['vessel_id']),
                            CarbonImmutable::parse($data['from']),
                            CarbonImmutable::parse($data['to'])->endOfDay(),
                            array_map('intval', (array) $data['weekdays']),
                            array_values((array) $data['times']),
                            filled($data['seats'] ?? null) ? (int) $data['seats'] : null,
                        );
                        Notification::make()->success()->title($created.' '.($created === 1 ? 'sailing' : 'sailings').' added')->send();
                    } catch (DeskRefusal $refusal) {
                        Notification::make()->danger()->title($refusal->getMessage())->send();
                    }
                }),
            CreateAction::make()->label('Add one sailing'),
        ];
    }
}
