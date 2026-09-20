<?php

namespace App\Filament\Resources\Knowledge;

use App\Filament\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Knowledge\Pages\ListKnowledgeArticles;
use App\Filament\Resources\Knowledge\Schemas\KnowledgeArticleForm;
use App\Filament\Resources\Knowledge\Tables\KnowledgeArticlesTable;
use App\Models\KnowledgeArticle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Knowledge Centre — §7.1.
 *
 * The badge counts **articles waiting on a scholar**, because that is the
 * only number here anybody can act on. A count of articles would say
 * "somebody has been writing", which is not news; a count of drafts stuck
 * in review is a person who has not been asked.
 */
class KnowledgeArticleResource extends Resource
{
    protected static ?string $model = KnowledgeArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Knowledge Centre';

    protected static ?string $modelLabel = 'article';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return KnowledgeArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeArticlesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ReferencesRelationManager::class];
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = KnowledgeArticle::awaitingAScholar()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * The breadcrumb root, which otherwise comes from the model label and
     * read "Articles" — the name of the *blog* resource, three items up
     * the same navigation group. Two different things called the same
     * thing is worse here than anywhere else in this panel.
     */
    public static function getBreadcrumb(): string
    {
        return 'Knowledge Centre';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeArticles::route('/'),
            'edit' => Pages\EditKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
