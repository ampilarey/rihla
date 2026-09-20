<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Models\DepartureHotel;
use App\Models\Room;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('departure_hotel_id')
                    ->label('Hotel')
                    ->required()
                    ->searchable()
                    ->preload()
                    // Upcoming departures only: adding a room to a trip
                    // that came home last March is always a mis-click.
                    ->options(fn (): array => DepartureHotel::query()
                        ->whereHas('departure', fn ($query) => $query->where('date_start', '>=', now()->startOfDay()))
                        ->with('departure.package')
                        ->get()
                        ->mapWithKeys(fn (DepartureHotel $hotel): array => [
                            $hotel->getKey() => sprintf(
                                '%s — %s (%s)',
                                $hotel->cityLabel(),
                                $hotel->name,
                                $hotel->departure->date_start->format('j M Y'),
                            ),
                        ])
                        ->all()),

                TextInput::make('label')
                    ->label('Room')
                    ->required()
                    ->maxLength(40)
                    ->helperText('What the hotel calls it — 412, "Deluxe 3". Theirs, not ours.'),

                TextInput::make('capacity')
                    ->label('Beds')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->default(4)
                    // Said out loud because the two disagree often enough to
                    // matter, and conflating them makes the conflict report
                    // lie.
                    ->helperText('How many beds are actually in it — not the rate the booking was sold at.'),

                Select::make('gender')
                    ->label('Who it is for')
                    ->options([
                        Room::MALE => 'Men',
                        Room::FEMALE => 'Women',
                        Room::FAMILY => 'Family (mixed, on purpose)',
                    ])
                    ->helperText('Leave blank and the rooming check will say nobody has decided.'),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }
}
