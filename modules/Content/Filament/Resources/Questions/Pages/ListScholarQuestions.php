<?php

namespace Modules\Content\Filament\Resources\Questions\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Content\Filament\Resources\Questions\ScholarQuestionResource;

class ListScholarQuestions extends ListRecords
{
    protected static string $resource = ScholarQuestionResource::class;

    protected static ?string $title = 'Ask a Scholar';

    /** No "new question" button: a pilgrim asks, the office does not. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
