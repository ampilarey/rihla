<?php

namespace App\Filament\Resources\Incidents\Schemas;

use App\Models\Departure;
use App\Models\Incident;
use App\Models\Traveller;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class IncidentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What happened')->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Departure')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->options(fn (): array => Departure::query()
                        ->with('package')
                        // Not upcoming-only: an incident is typed up on the
                        // trip, and often on the last day or after landing.
                        ->where('date_end', '>=', now()->subMonths(3))
                        ->orderByDesc('date_start')
                        ->get()
                        ->mapWithKeys(fn (Departure $departure): array => [
                            $departure->getKey() => sprintf(
                                '%s (%s)',
                                $departure->package->title ?? 'Departure',
                                $departure->date_start->format('j M Y'),
                            ),
                        ])
                        ->all()),

                Select::make('traveller_id')
                    ->label('Who it happened to')
                    ->searchable()
                    // Only the people actually on this departure. A global
                    // traveller list is how an incident gets filed against
                    // the wrong Ibrahim.
                    ->options(fn (Get $get): array => self::travellersOn($get('departure_id')))
                    ->helperText('Leave blank when it is not about one person — a coach that never arrived, say.'),

                Select::make('severity')
                    ->label('How bad')
                    ->required()
                    ->default(Incident::MINOR)
                    ->options([
                        Incident::EMERGENCY => 'Emergency — somebody needs to act now',
                        Incident::SERIOUS => 'Serious — the office needs to know today',
                        Incident::MINOR => 'Minor — handled on the spot, recorded so it is not lost',
                    ]),

                Select::make('category')
                    ->label('What kind')
                    ->required()
                    ->default(Incident::OTHER)
                    ->options([
                        Incident::MEDICAL => 'Medical',
                        Incident::LOST_DOCUMENT => 'Lost document',
                        Incident::MISSING_PERSON => 'Missing person',
                        Incident::TRANSPORT => 'Transport',
                        Incident::ACCOMMODATION => 'Accommodation',
                        Incident::CONDUCT => 'Conduct',
                        Incident::OTHER => 'Other',
                    ]),

                TextInput::make('summary')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('One line somebody can read at a glance.'),

                Textarea::make('detail')->rows(4)->columnSpanFull(),
            ]),

            Section::make('When and where')->columns(2)->schema([
                DateTimePicker::make('happened_at')
                    ->label('When it happened')
                    ->required()
                    ->default(now())
                    ->seconds(false)
                    // Said out loud because these are routinely hours apart
                    // on a trip, and a report timed by the typing is useless
                    // for working out what led to what.
                    ->helperText('Not when you are typing this — when it actually happened.'),

                TextInput::make('location')
                    ->maxLength(255)
                    ->helperText('The Haram, the hotel lobby, the coach from Madinah.'),
            ]),
        ]);
    }

    /** @return array<int, string> */
    private static function travellersOn(mixed $departureId): array
    {
        if (blank($departureId)) {
            return [];
        }

        return Traveller::query()
            ->whereHas(
                'bookingTravellers.booking',
                fn ($query) => $query->where('departure_id', $departureId),
            )
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }
}
