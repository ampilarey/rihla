<?php

namespace App\Filament\Resources\OperationsLog\Schemas;

use App\Models\Departure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OperationsLogEntryForm
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

                DatePicker::make('happened_on')
                    ->label('The day it is about')
                    ->required()
                    ->default(now())
                    // Said out loud: a log written up the next morning is
                    // still about yesterday, and dating it by the typing
                    // makes the account of the trip wrong by a day.
                    ->helperText('Not the day you are writing it — the day it is about.'),

                Textarea::make('body')
                    ->label('What happened')
                    ->required()
                    ->rows(6)
                    ->columnSpanFull()
                    ->helperText('The ordinary account of the day. Anything that went wrong belongs in Incidents, where it gets an owner.'),
            ]),
        ]);
    }
}
