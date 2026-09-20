<?php

namespace App\Filament\Resources\RollCalls\Schemas;

use App\Models\Departure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RollCallForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Departure')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Departure::query()
                        ->with('package')
                        // A count is taken on the trip, so the list runs
                        // from a little before departure to a little after
                        // the return.
                        ->where('date_end', '>=', now()->subWeeks(2))
                        ->orderBy('date_start')
                        ->get()
                        ->mapWithKeys(fn (Departure $departure): array => [
                            $departure->getKey() => sprintf(
                                '%s (%s)',
                                $departure->package->title ?? 'Departure',
                                $departure->date_start->format('j M Y'),
                            ),
                        ])
                        ->all()),

                TextInput::make('moment')
                    ->label('The moment')
                    ->required()
                    ->maxLength(255)
                    // Free text rather than a fixed list: the moments that
                    // matter differ by itinerary, and a closed list would
                    // be wrong for the first trip that needs another one.
                    ->helperText('Where somebody could be left behind — "Boarding at Velana", "Off the coach in Madinah".'),

                DateTimePicker::make('taken_at')
                    ->label('When it was taken')
                    ->required()
                    ->default(now())
                    ->seconds(false),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }
}
