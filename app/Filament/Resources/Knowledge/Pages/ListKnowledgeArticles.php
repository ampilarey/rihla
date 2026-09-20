<?php

namespace App\Filament\Resources\Knowledge\Pages;

use App\Filament\Resources\Knowledge\KnowledgeArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeArticles extends ListRecords
{
    protected static string $resource = KnowledgeArticleResource::class;

    protected static ?string $title = 'Knowledge Centre';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start an article')];
    }
}
