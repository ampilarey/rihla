<?php

namespace App\Filament\Resources\Ziyarah\Schemas;

use App\Models\ZiyarahLocation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ZiyarahLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name.en')
                    ->label('Name (English)')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')->required()->maxLength(255),

                Select::make('city')
                    ->required()
                    ->options(fn (): array => collect(ZiyarahLocation::CITIES)
                        ->mapWithKeys(fn (string $c): array => [
                            $c => (new ZiyarahLocation(['city' => $c]))->cityLabel(),
                        ])
                        ->all()),

                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower comes first within its city.'),

                Textarea::make('summary.en')
                    ->label('In one or two sentences')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText('What a pilgrim standing outside needs to know first.'),
            ]),

            Section::make('The page itself')
                ->description('Every claim here needs a source on the Sources tab. If you are not sure of one, leave the claim out rather than writing around it.')
                ->schema([
                    Textarea::make('significance.en')->label('Why it matters')->rows(6),
                    Textarea::make('history.en')->label('History')->rows(8),
                    Textarea::make('etiquette.en')
                        ->label('Etiquette')
                        ->rows(6)
                        ->helperText('What to do and what not to. This is the section a pilgrim reads at the gate.'),
                    Textarea::make('best_time.en')
                        ->label('Best time to visit')
                        ->rows(2)
                        ->helperText('Crowds and heat, not opening hours we would have to keep correct.'),
                ]),

            Section::make('Where it is')
                // Said on the screen so nobody adds an embed and wonders
                // why the page shows a grey rectangle.
                ->description('Coordinates produce a link out to the map application on the visitor\'s phone. There is no embedded map: nobody has supplied a Google Maps key, and an unkeyed embed renders a grey box stamped "for development purposes only".')
                ->columns(2)
                ->schema([
                    TextInput::make('latitude')->numeric()->step('0.0000001'),
                    TextInput::make('longitude')->numeric()->step('0.0000001'),
                ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English. AGENTS.md records what machine-filled Dhivehi has already cost this site, and religious text is the worst place to repeat it.')
                ->collapsed()
                ->schema([
                    TextInput::make('name.dv')->label('Name (Dhivehi)')->maxLength(255),
                    Textarea::make('summary.dv')->label('Summary (Dhivehi)')->rows(2),
                    Textarea::make('significance.dv')->label('Why it matters (Dhivehi)')->rows(6),
                    Textarea::make('history.dv')->label('History (Dhivehi)')->rows(8),
                    Textarea::make('etiquette.dv')->label('Etiquette (Dhivehi)')->rows(6),
                    Textarea::make('best_time.dv')->label('Best time (Dhivehi)')->rows(2),
                ]),
        ]);
    }
}
