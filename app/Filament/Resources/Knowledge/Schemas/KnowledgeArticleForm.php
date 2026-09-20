<?php

namespace App\Filament\Resources\Knowledge\Schemas;

use App\Models\KnowledgeArticle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class KnowledgeArticleForm
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

                Select::make('category')
                    ->required()
                    ->options(fn (): array => collect(KnowledgeArticle::CATEGORIES)
                        ->mapWithKeys(fn (string $c): array => [
                            $c => (new KnowledgeArticle(['category' => $c]))->categoryLabel(),
                        ])
                        ->all()),

                Textarea::make('summary.en')->label('Summary (English)')->rows(2)->columnSpanFull(),

                Textarea::make('body.en')
                    ->label('Article (English)')
                    ->rows(12)
                    ->columnSpanFull()
                    // Said on the screen, because the person typing is the
                    // one who has to resist filling a gap.
                    ->helperText('Every claim needs a source on the References tab. If you are not sure of one, leave the claim out rather than writing around it.'),
            ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English. AGENTS.md records what machine-filled Dhivehi has already cost this site, and religious text is the worst place to repeat it.')
                ->collapsed()
                ->schema([
                    TextInput::make('title.dv')->label('Title (Dhivehi)')->maxLength(255),
                    Textarea::make('summary.dv')->label('Summary (Dhivehi)')->rows(2),
                    Textarea::make('body.dv')->label('Article (Dhivehi)')->rows(12),
                ]),
        ]);
    }
}
