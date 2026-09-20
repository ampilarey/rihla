<?php

namespace App\Filament\Resources\Ziyarah\RelationManagers;

use App\Models\ArticleReference;
use App\Models\LocationMisconception;
use App\Models\ZiyarahLocation;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Common misconceptions — the part of §7.2 that earns the feature.
 *
 * ## The sources sit inside the form, not in their own relation manager
 *
 * Filament does not nest relation managers, and a correction is the one
 * place on this page where the source has to be entered in the same breath
 * as the claim. A separate screen would make "add the source" a second
 * errand, and a second errand is the one that does not get run — which is
 * exactly the state {@see ZiyarahLocation::whyNotApprovable()}
 * refuses to approve.
 */
class MisconceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'misconceptions';

    protected static ?string $title = 'Common misconceptions';

    protected static ?string $modelLabel = 'correction';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('belief.en')
                ->label('What pilgrims are told')
                ->required()
                ->rows(3)
                ->helperText('Stated the way somebody would actually say it. A softened version is one a reader does not recognise as the thing they were told.'),

            Textarea::make('correction.en')
                ->label('What is actually the case')
                ->required()
                ->rows(4),

            TextInput::make('sort_order')->numeric()->default(0),

            Repeater::make('references')
                ->label('Sources for this correction')
                ->relationship()
                ->helperText('A correction a pilgrim is asked to believe over what they were told needs to be the better-sourced of the two. This location cannot be signed off while any correction here has none.')
                ->defaultItems(1)
                ->columns(2)
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
                        // Only for a hadith, and required there. The model
                        // enforces the same rule and would throw anyway.
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
                ->description('Left blank, a reader sees the English. AGENTS.md records what machine-filled Dhivehi has already cost this site, and a correction about religion is the worst place to repeat it.')
                ->collapsed()
                ->schema([
                    Textarea::make('belief.dv')->label('What pilgrims are told (Dhivehi)')->rows(3),
                    Textarea::make('correction.dv')->label('What is actually the case (Dhivehi)')->rows(4),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('belief')
                    ->label('What pilgrims are told')
                    ->wrap(),

                TextColumn::make('correction')
                    ->label('What is actually the case')
                    ->wrap(),

                TextColumn::make('references_count')
                    ->label('Sources')
                    ->counts('references')
                    ->alignCenter()
                    // Red at zero, and this one blocks approval. A value
                    // rather than a placeholder so the colour applies.
                    ->state(fn (LocationMisconception $record): string => (string) $record->references_count)
                    ->color(fn (LocationMisconception $record): string => $record->references_count > 0 ? 'gray' : 'danger'),
            ])
            ->defaultSort('sort_order')
            ->headerActions([CreateAction::make()->label('Add a correction')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('None recorded')
            ->emptyStateDescription('Only where one exists. §7.2 asks for the things pilgrims are wrongly told about a place, not for one per place.');
    }
}
