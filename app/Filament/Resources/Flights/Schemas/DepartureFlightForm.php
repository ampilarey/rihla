<?php

namespace App\Filament\Resources\Flights\Schemas;

use App\Filament\Support\DepartureOptions;
use App\Models\DepartureFlight;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepartureFlightForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Departure')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => DepartureOptions::all(recentOnly: true)),

                Select::make('direction')
                    ->required()
                    ->default(DepartureFlight::OUTBOUND)
                    ->options(collect(DepartureFlight::DIRECTIONS)
                        ->mapWithKeys(fn (string $d): array => [$d => DepartureFlight::directionLabel($d)])
                        ->all()),

                TextInput::make('airline')->required()->maxLength(100)->placeholder('Saudia'),

                TextInput::make('flight_number')->label('Flight number')->required()->maxLength(20)->placeholder('SV 3301'),

                TextInput::make('from_airport')
                    ->label('From')
                    ->required()
                    ->length(3)
                    ->regex('/^[A-Za-z]{3}$/')
                    ->placeholder('MLE')
                    ->helperText('Three-letter airport code.')
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),

                TextInput::make('to_airport')
                    ->label('To')
                    ->required()
                    ->length(3)
                    ->regex('/^[A-Za-z]{3}$/')
                    ->placeholder('JED')
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),

                // Stored exactly as typed and never converted. Said on the
                // screen because the natural mistake is to type everything
                // in Malé time.
                DateTimePicker::make('departs_at')
                    ->label('Departs')
                    ->required()
                    ->seconds(false)
                    ->timezone('UTC')
                    ->helperText('Local time at the airport it leaves from — as printed on the ticket.'),

                DateTimePicker::make('arrives_at')
                    ->label('Arrives')
                    ->seconds(false)
                    ->timezone('UTC')
                    ->helperText('Local time at the airport it lands at.'),

                TextInput::make('seats')
                    ->label('Seats held on this leg')
                    ->integer()
                    ->minValue(0)
                    ->helperText('If filled in, the departure board warns when more people are travelling than there are seats.'),

                TextInput::make('booking_reference')
                    ->label('Booking reference (PNR)')
                    ->maxLength(50)
                    ->helperText('Staff only. Never shown in the portal: a reference is enough to change a booking on most airline sites.'),

                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
