<?php

namespace App\Filament\Resources\Learning\RelationManagers;

use App\Models\ArticleReference;
use App\Models\QuizQuestion;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The quiz — §7.3.
 *
 * ## The answers and the sources are both in the question's own form
 *
 * Filament does not nest relation managers, and neither of these is a
 * second errand: a question without its answers is not a question, and a
 * question that tells a pilgrim they were wrong without a source is
 * somebody's opinion delivered as a verdict.
 *
 * ## The table says which questions are not usable yet
 *
 * A question with no correct answer marks every pilgrim wrong; one where
 * every answer is correct teaches nothing. Both are the kind of mistake
 * that is invisible in a form and obvious in a list, so the list is where
 * it is named.
 */
class QuizQuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'quizQuestions';

    protected static ?string $title = 'Questions';

    protected static ?string $modelLabel = 'question';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('prompt.en')
                ->label('The question')
                ->required()
                ->rows(2),

            Textarea::make('explanation.en')
                ->label('What to say afterwards')
                ->rows(3)
                ->helperText('Shown whether they got it right or wrong. The mark tells a pilgrim they have a problem; this tells them what it is.'),

            TextInput::make('sort_order')->numeric()->default(0),

            Repeater::make('options')
                ->label('The answers')
                ->relationship()
                ->defaultItems(2)
                ->minItems(2)
                ->columns(2)
                ->helperText('At least one has to be right and at least one wrong, or the question either marks everybody wrong or teaches nothing.')
                ->schema([
                    Textarea::make('text.en')->label('Answer')->required()->rows(2)->columnSpan(1),

                    Toggle::make('is_correct')
                        ->label('This one is right')
                        // Several of §7.3's topics genuinely have more than
                        // one right answer, so this is a toggle per answer
                        // rather than a single choice.
                        ->helperText('More than one may be.'),

                    TextInput::make('sort_order')->numeric()->default(0),
                ]),

            Repeater::make('references')
                ->label('Sources for this answer')
                ->relationship()
                ->defaultItems(1)
                ->columns(2)
                ->helperText('A question that tells a pilgrim they were wrong about a rite needs a source more than an article does, not less.')
                ->schema([
                    Select::make('kind')
                        ->label('What kind')
                        ->required()
                        ->live()
                        ->default(ArticleReference::QURAN)
                        ->options([
                            ArticleReference::QURAN => "Qur'an",
                            ArticleReference::HADITH => 'Hadith',
                        ]),

                    TextInput::make('citation')->required()->maxLength(255),

                    Select::make('grading')
                        ->label('Grading')
                        ->visible(fn (Get $get): bool => $get('kind') === ArticleReference::HADITH)
                        ->required(fn (Get $get): bool => $get('kind') === ArticleReference::HADITH)
                        ->options([
                            ArticleReference::SAHIH => 'Sahih — authentic',
                            ArticleReference::HASAN => 'Hasan — good',
                            ArticleReference::DAIF => "Da'if — weak",
                            ArticleReference::MAWDU => "Mawdu' — fabricated",
                            ArticleReference::DISPUTED => 'Disputed among scholars',
                        ]),

                    Textarea::make('note')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Dhivehi')
                ->description('Left blank, a reader sees the English. AGENTS.md records what machine-filled Dhivehi has already cost this site.')
                ->collapsed()
                ->schema([
                    Textarea::make('prompt.dv')->label('The question (Dhivehi)')->rows(2),
                    Textarea::make('explanation.dv')->label('What to say afterwards (Dhivehi)')->rows(3),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('prompt')->label('Question')->wrap(),

                TextColumn::make('options_count')
                    ->label('Answers')
                    ->counts('options')
                    ->alignCenter(),

                TextColumn::make('references_count')
                    ->label('Sources')
                    ->counts('references')
                    ->alignCenter()
                    ->state(fn (QuizQuestion $record): string => (string) $record->references_count)
                    ->color(fn (QuizQuestion $record): string => $record->references_count > 0 ? 'gray' : 'danger'),

                TextColumn::make('usable')
                    ->label('Ready')
                    ->badge()
                    // A value rather than a placeholder, so the colour
                    // applies — this is the cell that has to be noticed.
                    ->state(fn (QuizQuestion $record): string => $record->whyNotUsable() ?? 'Ready')
                    ->color(fn (QuizQuestion $record): string => $record->whyNotUsable() === null ? 'success' : 'danger')
                    ->wrap(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add a question')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No questions yet')
            ->emptyStateDescription('Optional. A module with no quiz is a normal module — §7.3 asks for quizzes, not for one per page.');
    }
}
