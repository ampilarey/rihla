<?php

namespace App\Filament\Resources\Checklists\Schemas;

use App\Filament\Support\DepartureOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepartureChecklistItemForm
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

                DatePicker::make('due_on')->label('Due')->native(false),

                TextInput::make('title')
                    ->label('What has to be done')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Toggle::make('is_blocking')
                    ->label('The departure cannot go without it')
                    ->helperText('Overdue and not done, it shows on the departure board as a blocker rather than something to look at.')
                    ->columnSpanFull(),

                TextInput::make('sort_order')->label('Order')->integer()->default(0),

                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
