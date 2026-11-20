<?php

namespace Modules\Umrah\Filament\Resources\BusGroups\Schemas;

use App\Filament\Support\DepartureOptions;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
use Modules\Umrah\Models\FlightGroup;

class BusGroupForm
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
                    ->disabledOn('edit')
                    ->live()
                    // Recent and upcoming only: a bus for a trip that came
                    // home last March is always a mis-click.
                    ->options(fn (): array => DepartureOptions::all(recentOnly: true)),

                TextInput::make('name')
                    ->label('Bus')
                    ->required()
                    ->maxLength(40)
                    ->placeholder('Bus 1')
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('departure_id', $get('departure_id')),
                    )
                    ->validationMessages(['unique' => 'This departure already has a bus called that.']),

                Select::make('flight_group_id')
                    ->label('Flight group')
                    ->native(false)
                    ->options(fn (Get $get): array => blank($get('departure_id')) ? [] : FlightGroup::query()
                        ->where('departure_id', $get('departure_id'))
                        ->orderBy('sort_order')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('The flight this bus meets. Leave blank when the whole departure flies together.'),

                ColorPicker::make('colour')
                    ->label('Colour')
                    ->hex()
                    ->regex('/^#[0-9a-fA-F]{6}$/')
                    ->helperText('The sticker, lanyard and flag colour people look for at the airport.'),

                TextInput::make('capacity')
                    ->label('Seats')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99)
                    ->helperText('Leave blank until somebody has counted; a bus with no number is never "over capacity".'),

                TextInput::make('sort_order')
                    ->label('Order')
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->helperText('Bus 1 before Bus 2 on the lists.'),

                TextInput::make('leader_name')
                    ->label('Leader')
                    ->maxLength(80)
                    ->helperText('Whoever stands at its door. Blank means the departure\'s tour leader.'),

                TextInput::make('leader_phone')
                    ->label('Leader phone')
                    ->tel()
                    ->maxLength(40),

                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('Picks up from the Madinah hotel first; the wheelchair goes in the hold.'),
            ]),
        ]);
    }
}
