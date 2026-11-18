<?php

namespace App\Filament\Resources\Excursions\Schemas;

use App\Filament\Support\DepartureOptions;
use App\Models\DepartureExcursion;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExcursionForm
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
                    ->options(fn (): array => DepartureOptions::all(recentOnly: true)),

                TextInput::make('title')->required()->maxLength(120)->placeholder('Taif day trip'),

                Select::make('city')
                    ->options(['taif' => 'Taif', 'jeddah' => 'Jeddah', 'makkah' => 'Makkah', 'madinah' => 'Madinah', 'other' => 'Other'])
                    ->native(false),

                DatePicker::make('scheduled_on')->label('On')->native(false),

                Toggle::make('is_included')
                    ->label('Included in the package price')
                    ->live()
                    ->helperText('An included trip is signed up for at no charge, so the lists still say who is going.'),

                Select::make('currency')
                    ->options(array_combine(DepartureExcursion::CURRENCIES, DepartureExcursion::CURRENCIES))
                    ->default('SAR')
                    ->required()
                    ->native(false)
                    ->live(),

                TextInput::make('price_minor')
                    ->label('Price per person')
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn (Get $get): string => (string) ($get('currency') ?: 'SAR'))
                    ->hidden(fn (Get $get): bool => (bool) $get('is_included'))
                    ->formatStateUsing(fn ($state): ?int => $state === null ? null : intdiv((int) $state, 100))
                    ->dehydrateStateUsing(fn ($state): int => Money::ofMajor((int) $state)->minor)
                    ->helperText('A price changed later applies to people signed up after it; nobody\'s agreed price moves.'),

                TextInput::make('capacity')->label('Places')->integer()->minValue(1)->helperText('Blank for no limit.'),

                TextInput::make('provider')->maxLength(120)->placeholder('Who runs it'),

                TextInput::make('sort_order')->label('Order')->integer()->minValue(0)->default(0),

                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }
}
