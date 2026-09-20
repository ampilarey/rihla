<?php

namespace App\Filament\Resources\Questions;

use App\Filament\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Questions\Pages\ListScholarQuestions;
use App\Filament\Resources\Questions\Tables\ScholarQuestionsTable;
use App\Models\ScholarQuestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Ask a Scholar — §6.4.
 *
 * No create page and no edit page. Nobody in the office writes a question;
 * a pilgrim does, and the text is theirs. Everything staff can do to one —
 * answer it, decline it, publish it with the asker's consent — is an action
 * on the row, which keeps the question itself read-only on every screen.
 */
class ScholarQuestionResource extends Resource
{
    protected static ?string $model = ScholarQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Ask a Scholar';

    protected static ?string $modelLabel = 'question';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return ScholarQuestionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ReferencesRelationManager::class];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = ScholarQuestion::waiting()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return ['index' => ListScholarQuestions::route('/')];
    }
}
