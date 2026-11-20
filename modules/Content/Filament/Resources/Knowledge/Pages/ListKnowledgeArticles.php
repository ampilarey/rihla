<?php

namespace Modules\Content\Filament\Resources\Knowledge\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\Knowledge\KnowledgeArticleResource;

class ListKnowledgeArticles extends ListRecords
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected static ?string $title = 'Knowledge Centre';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start an article')];
    }
}
