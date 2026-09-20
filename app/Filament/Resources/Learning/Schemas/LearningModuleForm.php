<?php

namespace App\Filament\Resources\Learning\Schemas;

use App\Models\ZiyarahLocation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LearningModuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('title.en')
                    ->label('Title (English)')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')->required()->maxLength(255),

                Textarea::make('summary.en')
                    ->label('In one or two sentences')
                    ->rows(2)
                    ->columnSpanFull(),

                Textarea::make('body.en')
                    ->label('The module (English)')
                    ->rows(14)
                    ->columnSpanFull()
                    ->helperText('Every claim needs a source on the Sources tab. If you are not sure of one, leave the claim out rather than writing around it.'),
            ]),

            Section::make('When a pilgrim sees this')
                // The sentence that explains the whole feature, on the one
                // screen where somebody sets the number it turns on.
                ->description('A pilgrim\'s plan is worked out from their departure date. A module set to 30 appears on their list 30 days before they fly, and moves by itself if the departure moves.')
                ->columns(2)
                ->schema([
                    TextInput::make('days_before_departure')
                        ->label('Days before departure')
                        ->numeric()
                        ->required()
                        ->default(30)
                        ->minValue(0)
                        ->maxValue(365)
                        ->helperText('§7.3 suggests 60, 30 and 7. Nothing enforces those three.'),

                    TextInput::make('minutes')
                        ->label('Roughly how many minutes')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(240)
                        ->helperText('Shown to the reader. "About 6 minutes" is what decides whether somebody starts it now.'),

                    Select::make('ziyarah_location_id')
                        ->label('About a place we visit')
                        ->searchable()
                        ->columnSpanFull()
                        ->options(fn (): array => ZiyarahLocation::query()
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn (ZiyarahLocation $l): array => [$l->getKey() => $l->name.' ('.$l->cityLabel().')'])
                            ->all())
                        // Says what it actually does, including the way it
                        // can miss: the office is the only party that can
                        // fix a spelling mismatch, so the office is told.
                        ->helperText('Set this and the module appears only for departures whose itinerary mentions that place. The match is on the name as written in the itinerary, so the departure board lists any that did not match rather than dropping them quietly.'),
                ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English. AGENTS.md records what machine-filled Dhivehi has already cost this site, and a lesson somebody is about to act on is the worst place to repeat it.')
                ->collapsed()
                ->schema([
                    TextInput::make('title.dv')->label('Title (Dhivehi)')->maxLength(255),
                    Textarea::make('summary.dv')->label('Summary (Dhivehi)')->rows(2),
                    Textarea::make('body.dv')->label('The module (Dhivehi)')->rows(14),
                ]),
        ]);
    }
}
