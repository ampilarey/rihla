<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    use EditsTranslations;

    protected static string $resource = ArticleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $article = $this->article();

        $translations = [];

        foreach ($article->translatable as $attribute) {
            $translations[$attribute] = $article->getTranslations($attribute);
        }

        return self::withTranslationArrays($data, $translations);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return self::withoutEmptyLocales($data, $this->article()->translatable);
    }

    private function article(): Article
    {
        /** @var Article */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
