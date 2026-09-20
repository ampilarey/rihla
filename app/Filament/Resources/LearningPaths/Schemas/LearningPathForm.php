<?php

namespace App\Filament\Resources\LearningPaths\Schemas;

use App\Models\LearningModule;
use App\Models\LearningPath;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class LearningPathForm
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

                Select::make('audience')
                    ->label('Who it is for')
                    ->required()
                    ->options(fn (): array => collect(LearningPath::AUDIENCES)
                        ->mapWithKeys(fn (string $a): array => [
                            $a => (new LearningPath(['audience' => $a]))->audienceLabel(),
                        ])
                        ->all()),

                TextInput::make('sort_order')->numeric()->default(0),

                Textarea::make('summary.en')->label('Summary (English)')->rows(2)->columnSpanFull(),

                Select::make('modules')
                    ->label('Modules, in order')
                    ->multiple()
                    ->relationship(titleAttribute: 'slug')
                    ->columnSpanFull()
                    ->options(fn (): array => LearningModule::query()
                        ->orderByDesc('days_before_departure')
                        ->get()
                        ->mapWithKeys(fn (LearningModule $m): array => [$m->getKey() => $m->title])
                        ->all())
                    // Said plainly, because a path listing eight modules of
                    // which two are still with a scholar looks broken on the
                    // pilgrim's side otherwise.
                    ->helperText('A module still with a scholar can be put in a path — it simply does not appear to a pilgrim until it is signed off. The alternative would let one unreviewed module withhold ten finished ones.'),

                Toggle::make('is_published')
                    ->label('Offer this path to pilgrims')
                    ->helperText('An office decision. Everything in the path was signed off by a named scholar before it could be put in.'),
            ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English.')
                ->collapsed()
                ->schema([
                    TextInput::make('name.dv')->label('Name (Dhivehi)')->maxLength(255),
                    Textarea::make('summary.dv')->label('Summary (Dhivehi)')->rows(2),
                ]),
        ]);
    }
}
