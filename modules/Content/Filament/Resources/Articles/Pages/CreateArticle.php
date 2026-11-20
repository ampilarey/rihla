<?php

namespace Modules\Content\Filament\Resources\Articles\Pages;

use App\Filament\Concerns\EditsTranslations;
use Filament\Resources\Pages\CreateRecord;
use Modules\Content\Filament\Resources\Articles\ArticleResource;
use Modules\Content\Models\Article;

class CreateArticle extends CreateRecord
{
    use EditsTranslations;

    protected static string $resource = ArticleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::withoutEmptyLocales($data, (new Article)->translatable);
    }
}
