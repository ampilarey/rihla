<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Resources\Questions\ScholarQuestionResource;
use Filament\Resources\Pages\ListRecords;

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
