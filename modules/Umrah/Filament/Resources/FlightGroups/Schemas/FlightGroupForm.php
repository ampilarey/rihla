<?php

namespace Modules\Umrah\Filament\Resources\FlightGroups\Schemas;

use App\Filament\Support\DepartureOptions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
use Modules\Umrah\Models\DepartureFlight;
use Modules\Umrah\Models\FlightGroup;

class FlightGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Departure')
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->disabledOn('edit')
                    ->options(fn (): array => DepartureOptions::all(recentOnly: true)),

                TextInput::make('name')
                    ->label('Group')
                    ->required()
                    ->maxLength(40)
                    ->placeholder('Group A')
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('departure_id', $get('departure_id')),
                    )
                    ->validationMessages(['unique' => 'This departure already has a group called that.']),

                Select::make('outbound_flight_id')
                    ->label('Flight out')
                    ->native(false)
                    ->options(fn (Get $get): array => self::flights($get('departure_id'), DepartureFlight::OUTBOUND))
                    ->helperText('Add the departure\'s flights under Flights first.'),

                Select::make('return_flight_id')
                    ->label('Flight home')
                    ->native(false)
                    ->options(fn (Get $get): array => self::flights($get('departure_id'), DepartureFlight::RETURN)),

                TextInput::make('sort_order')
                    ->label('Order')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->helperText('Group A before Group B on the lists.'),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('Meets at the airport at 22:00; the leader carries the group visa.'),
            ]),
        ]);
    }

    /** @return array<int, string> */
    public static function flights(mixed $departureId, string $direction): array
    {
        if (blank($departureId)) {
            return [];
        }

        return DepartureFlight::query()
            ->where('departure_id', $departureId)
            ->where('direction', $direction)
            ->orderBy('departs_at')
            ->get()
            ->mapWithKeys(fn (DepartureFlight $flight): array => [$flight->getKey() => FlightGroup::flightPart($flight).' · '.$flight->from_airport.' → '.$flight->to_airport])
            ->all();
    }
}
