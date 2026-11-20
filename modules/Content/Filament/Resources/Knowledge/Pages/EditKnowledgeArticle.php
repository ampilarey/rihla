<?php

namespace Modules\Content\Filament\Resources\Knowledge\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Content\Filament\Resources\Knowledge\KnowledgeArticleResource;

/**
 * The edit page exists so the references relation manager has a page to
 * live on — §7.1's sources are the point of this feature, and a resource
 * with no detail page would leave them unreachable.
 */
class EditKnowledgeArticle extends EditRecord
{
    protected static string $resource = KnowledgeArticleResource::class;

    /** No delete. A withdrawn article keeps its reason. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
