<?php

namespace App\Filament\Resources\ExchangeRates\Schemas;

use App\Models\ExchangeRate;
use App\Support\Rates;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExchangeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        $foreign = array_values(array_filter(Rates::currencies(), fn (string $currency): bool => $currency !== Rates::base()));

        return $schema->components([
            Section::make()
                ->description('How many '.Rates::base().' one unit of the currency is worth, from the day given. Add a new line when the rate moves rather than changing the old one: a payment recorded in March keeps showing the rate March had.')
                ->columns(2)
                ->schema([
                    Select::make('currency')
                        ->options(array_combine($foreign, $foreign))
                        ->default($foreign[0] ?? null)
                        ->required(),

                    Hidden::make('to_currency')->default(Rates::base())->dehydrated(),

                    TextInput::make('rate')
                        ->label('1 unit = … '.Rates::base())
                        ->numeric()
                        ->step('0.0001')
                        ->minValue(0.000001)
                        ->required()
                        ->helperText('The bank\'s selling rate for the day, as the customer would be charged it. 15.42 means one dollar is credited as MVR 15.42.'),

                    DatePicker::make('effective_on')
                        ->label('From')
                        ->native(false)
                        ->default(now()->toDateString())
                        ->required()
                        // One line per currency per day. A closure rather
                        // than unique(): the column is a date the cast
                        // writes with a time, which a plain equality on the
                        // typed day never matches.
                        ->rules([
                            fn (Get $get, ?ExchangeRate $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                $taken = ExchangeRate::query()
                                    ->where('currency', strtoupper((string) $get('currency')))
                                    ->where('to_currency', Rates::base())
                                    ->whereDate('effective_on', (string) $value)
                                    ->when($record !== null, fn ($query) => $query->whereKeyNot($record?->getKey()))
                                    ->exists();

                                if ($taken) {
                                    $fail('A rate for that currency already starts on that day. Edit that line, or pick the next day.');
                                }
                            },
                        ]),

                    TextInput::make('note')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->helperText('Where it came from — "BML selling rate", "agent\'s invoice" — so a figure can be checked later.'),
                ]),
        ]);
    }
}
