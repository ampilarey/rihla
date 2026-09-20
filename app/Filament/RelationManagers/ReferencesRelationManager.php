<?php

namespace App\Filament\RelationManagers;

use App\Models\ArticleReference;
use App\Models\Concerns\EditorialGate;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The sources — §7.1's "every claim carries a source".
 *
 * Shared by the Knowledge Centre and the Ziyarah Guide rather than copied
 * into each, for the reason {@see EditorialGate}
 * gives: the second copy of a rule is the one that drifts.
 *
 * The grading field appears only for a hadith and is required there. The
 * model enforces the same rule and would throw regardless; this is the
 * screen agreeing with it rather than the screen being the rule.
 */
class ReferencesRelationManager extends RelationManager
{
    protected static string $relationship = 'references';

    protected static ?string $title = 'Sources';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')
                ->label('What kind')
                ->required()
                ->live()
                ->default(ArticleReference::QURAN)
                ->options([
                    ArticleReference::QURAN => "Qur'an",
                    ArticleReference::HADITH => 'Hadith',
                ]),

            TextInput::make('citation')
                ->required()
                ->maxLength(255)
                ->helperText('However the collection is normally cited — chapter and verse, or collection and number.'),

            Select::make('grading')
                ->label('Grading')
                // Only for a hadith, and required there. A verse has no
                // grading, and putting one on it is a category error a
                // reader would take seriously.
                ->visible(fn (Get $get): bool => $get('kind') === ArticleReference::HADITH)
                ->required(fn (Get $get): bool => $get('kind') === ArticleReference::HADITH)
                ->options([
                    ArticleReference::SAHIH => 'Sahih — authentic',
                    ArticleReference::HASAN => 'Hasan — good',
                    ArticleReference::DAIF => "Da'if — weak",
                    ArticleReference::MAWDU => "Mawdu' — fabricated",
                    ArticleReference::DISPUTED => 'Disputed among scholars',
                ])
                ->helperText('A weak or fabricated narration can stay — it is labelled for the reader, which is what stops them repeating it. It cannot be left blank.'),

            Textarea::make('note')
                ->rows(2)
                ->helperText('Optional. "Included as a caution — pilgrims are often told this."'),

            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label('Kind')
                    ->badge()
                    ->state(fn (ArticleReference $record): string => $record->kindLabel()),

                TextColumn::make('citation')->wrap(),

                TextColumn::make('grading')
                    ->label('Grading')
                    ->badge()
                    // A value rather than a placeholder, so the colour
                    // applies: a cautionary grading is the cell that has to
                    // be noticed.
                    ->state(fn (ArticleReference $record): string => $record->gradingLabel() ?: 'Not graded')
                    ->color(fn (ArticleReference $record): string => $record->isCautionary() ? 'danger' : 'gray'),

                TextColumn::make('note')->wrap()->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add a source')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No sources yet')
            ->emptyStateDescription('This cannot be signed off until it has one. Every claim carries a source (§7.1).');
    }
}
