<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Package;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('enquiry_id')
                    ->label('For which enquiry')
                    ->required()
                    ->searchable()
                    ->options(fn (): array => Enquiry::query()
                        ->open()
                        ->orderByDesc('created_at')
                        ->limit(100)
                        ->get()
                        ->mapWithKeys(fn (Enquiry $e): array => [
                            $e->getKey() => $e->name.' — '.$e->created_at?->translatedFormat('j M Y'),
                        ])
                        ->all()),

                TextInput::make('party_size')
                    ->label('How many people')
                    ->numeric()
                    ->required()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(60),

                Select::make('package_id')
                    ->label('Package')
                    ->searchable()
                    ->options(fn (): array => Package::query()
                        ->get()
                        ->mapWithKeys(fn (Package $p): array => [$p->getKey() => $p->title])
                        ->all())
                    ->helperText('Optional. An early quotation is often "roughly this, for a trip like that".'),

                Select::make('departure_id')
                    ->label('Departure')
                    ->searchable()
                    ->options(fn (): array => Departure::query()
                        ->whereDate('date_start', '>=', now()->toDateString())
                        ->orderBy('date_start')
                        ->with('package')
                        ->get()
                        ->mapWithKeys(fn (Departure $d): array => [
                            // `??` makes a null property read safe, which is
                            // why the package title needs no nullsafe. The
                            // date does not need one either: `date_start` is
                            // not a nullable column.
                            $d->getKey() => ($d->package->title ?? 'Departure')
                                .' — '.$d->date_start->translatedFormat('j M Y'),
                        ])
                        ->all()),
            ]),

            Section::make('The price')
                // Said on the screen because the field below takes whole
                // rufiyaa and the column stores laari, and somebody typing
                // 28500.50 needs to know what happens to the half.
                ->description('Whole rufiyaa (or whole dollars). This is the total for the whole party, not per person — the screen works out the per-person figure itself.')
                ->columns(2)
                ->schema([
                    Select::make('currency')
                        ->required()
                        ->default('MVR')
                        ->options(['MVR' => 'MVR — rufiyaa', 'USD' => 'USD — dollars']),

                    TextInput::make('total_minor')
                        ->label('Total')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        // Whole units on the screen, minor units in the
                        // column, and the conversion goes through Money
                        // both ways — nothing else in this codebase
                        // multiplies or divides by 100, which is the rule
                        // that class exists to hold.
                        ->formatStateUsing(fn (?int $state): ?int => $state === null
                            ? null
                            : Money::ofMinor($state)->major())
                        ->dehydrateStateUsing(fn ($state, Get $get): int => Money::ofMajor(
                            (int) $state,
                            (string) ($get('currency') ?: 'MVR'),
                        )->minor),

                    DatePicker::make('valid_until')
                        ->label('Valid until')
                        ->required()
                        ->default(now()->addDays(14))
                        ->minDate(now()->toDateString())
                        ->helperText('Required. A quotation with no end date is a price we are held to for ever — through a currency move and next season\'s hotel rates.'),
                ]),

            Section::make('What it covers')->schema([
                Textarea::make('includes')->label('Includes')->rows(4),
                Textarea::make('excludes')
                    ->label('Does not include')
                    ->rows(3)
                    ->helperText('The half that causes the argument. Worth writing even when it feels obvious.'),
            ]),
        ]);
    }
}
