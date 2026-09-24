<?php

namespace App\Filament\Resources\Transfers\Schemas;

use App\Filament\Support\DepartureOptions;
use App\Models\DepartureTransfer;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepartureTransferForm
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

                Select::make('mode')
                    ->label('By')
                    ->required()
                    ->default(DepartureTransfer::COACH)
                    ->options(collect(DepartureTransfer::MODES)
                        ->mapWithKeys(fn (string $m): array => [$m => DepartureTransfer::modeLabel($m)])
                        ->all()),

                TextInput::make('from_place')->label('From')->required()->maxLength(150)->placeholder('Jeddah airport'),

                TextInput::make('to_place')->label('To')->required()->maxLength(150)->placeholder('Hotel in Makkah'),

                DateTimePicker::make('starts_at')
                    ->label('Leaves')
                    ->required()
                    ->seconds(false)
                    ->timezone('UTC')
                    ->helperText('Local time where it leaves from.'),

                TextInput::make('meeting_point')
                    ->label('Meeting point')
                    ->maxLength(255)
                    ->placeholder('Hotel lobby, by the main doors')
                    ->helperText('Written for the pilgrim — this is shown in their portal.'),

                TextInput::make('provider')->label('Company')->maxLength(150),

                TextInput::make('contact_phone')
                    ->label('Driver or company phone')
                    ->tel()
                    ->maxLength(30)
                    ->helperText('For the office and the tour leader. Not shown to pilgrims.'),

                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }
}
