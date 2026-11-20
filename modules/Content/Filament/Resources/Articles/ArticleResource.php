<?php

namespace Modules\Content\Filament\Resources\Articles;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Content\Filament\Resources\Articles\Pages\CreateArticle;
use Modules\Content\Filament\Resources\Articles\Pages\EditArticle;
use Modules\Content\Filament\Resources\Articles\Pages\ListArticles;
use Modules\Content\Filament\Resources\Articles\Schemas\ArticleForm;
use Modules\Content\Filament\Resources\Articles\Tables\ArticlesTable;
use Modules\Content\Models\Article;
use UnitEnum;

/**
 * The blog, as a resource rather than a page builder.
 *
 * §4.4: the source specification proposes a visual page builder. That is a
 * multi-month project and Rihla does not need one. What it needs is somewhere
 * to answer the questions pilgrims ask before they book.
 */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $navigationLabel = 'Articles';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArticlesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
