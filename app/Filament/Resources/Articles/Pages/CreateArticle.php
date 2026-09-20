<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use Filament\Resources\Pages\CreateRecord;

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
