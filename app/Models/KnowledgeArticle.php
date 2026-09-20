<?php

namespace App\Models;

use App\Models\Concerns\EditorialGate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * An article in the Knowledge Centre — §7.1.
 *
 * ## The editorial standard is the state machine
 *
 * §7.1 asks for the standard to be "required fields, not a style guide
 * people forget", so the transitions enforce it and there is no path round
 * them. They live in {@see EditorialGate} rather than here, because §7.2's
 * Ziyarah locations make the same kind of claim and a second copy of the
 * rule is the copy that drifts.
 *
 * What is left in this file is what is actually about articles: the
 * categories, and the words the screen uses for them.
 */
class KnowledgeArticle extends Model
{
    use EditorialGate;
    use HasFactory;
    use HasTranslations;

    protected $fillable = ['slug', 'title', 'summary', 'body', 'category'];

    /** @var array<int, string> */
    public array $translatable = ['title', 'summary', 'body'];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::DRAFT];

    public const PLACE = 'place';

    public const EVENT = 'event';

    public const PERSON = 'person';

    public const DUA = 'dua';

    public const HISTORY = 'history';

    /** @var list<string> */
    public const CATEGORIES = [self::PLACE, self::EVENT, self::PERSON, self::DUA, self::HISTORY];

    protected function editorialSubject(): string
    {
        return 'article';
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::PLACE => 'Place',
            self::EVENT => 'Event',
            self::PERSON => 'Person',
            self::DUA => 'Dua',
            self::HISTORY => 'History',
            default => 'Unknown',
        };
    }
}
