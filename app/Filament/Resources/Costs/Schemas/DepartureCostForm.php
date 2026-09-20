<?php

namespace App\Filament\Resources\Costs\Schemas;

use App\Models\Departure;
use App\Models\DepartureCost;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DepartureCostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Which departure')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Departure::query()
                        ->with('package')
                        ->orderByDesc('date_start')
                        ->limit(100)
                        ->get()
                        ->mapWithKeys(fn (Departure $d): array => [
                            $d->getKey() => ($d->package->title ?? 'Departure').' — '.$d->date_start->format('j M Y'),
                        ])
                        ->all()),

                Select::make('category')
                    ->required()
                    ->options(fn (): array => collect(DepartureCost::CATEGORIES)
                        ->mapWithKeys(fn (string $c): array => [
                            $c => (new DepartureCost(['category' => $c]))->categoryLabel(),
                        ])
                        ->all()),

                TextInput::make('supplier')
                    ->maxLength(255)
                    ->helperText('Who was paid. A name somebody typed beats a supplier table nobody maintains.'),

                TextInput::make('description')->maxLength(255),
            ]),

            Section::make('The money')
                // The two sentences that decide whether the margin is right.
                ->description('Enter the unit price. "Per person" multiplies it by the number of travellers on the departure — a coach costs the same for eighteen as for thirty, and a hotel bed does not.')
                ->columns(2)
                ->schema([
                    Select::make('currency')
                        ->required()
                        ->default('MVR')
                        ->options(['MVR' => 'MVR — rufiyaa', 'USD' => 'USD — dollars', 'SAR' => 'SAR — riyals'])
                        ->helperText('Hotels bill in SAR and airlines in USD. Record what was actually billed; nothing is converted without a rate somebody has set.'),

                    TextInput::make('amount_minor')
                        ->label('Amount')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        // Whole units on the screen, minor units in the
                        // column, converted through Money both ways —
                        // nothing else here multiplies by 100.
                        ->formatStateUsing(fn (?int $state): ?int => $state === null
                            ? null
                            : Money::ofMinor($state)->major())
                        ->dehydrateStateUsing(fn ($state, Get $get): int => Money::ofMajor(
                            (int) $state,
                            (string) ($get('currency') ?: 'MVR'),
                        )->minor),

                    Toggle::make('is_per_person')
                        ->label('Per person')
                        ->helperText('Off means this is the whole cost for the departure however many travel.'),

                    Select::make('status')
                        ->required()
                        ->default(DepartureCost::ESTIMATED)
                        ->options(fn (): array => collect(DepartureCost::STATUSES)
                            ->mapWithKeys(fn (string $s): array => [
                                $s => (new DepartureCost(['status' => $s]))->statusLabel(),
                            ])
                            ->all())
                        ->helperText('An estimate is a plan, an agreed cost is a contract, a paid one is money gone. The profit report says which of the three it counted.'),

                    DatePicker::make('incurred_on')->label('Dated'),
                ]),
        ]);
    }
}
