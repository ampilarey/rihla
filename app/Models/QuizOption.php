<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/** One of the answers offered for a {@see QuizQuestion}. */
class QuizOption extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = ['quiz_question_id', 'text', 'is_correct', 'sort_order'];

    /** @var array<int, string> */
    public array $translatable = ['text'];

    protected $casts = [
        'is_correct' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return BelongsTo<QuizQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
