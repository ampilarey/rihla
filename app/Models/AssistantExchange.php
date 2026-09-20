<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question put to the pilgrim assistant, and what came back — §9.6.
 *
 * Append-only in practice: nothing updates a row, and the only deletion is
 * `assistant:prune` retiring old ones. A log somebody can edit is not a log.
 */
class AssistantExchange extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'referred' => 'boolean',
        'sources' => 'array',
    ];

    /** @return BelongsTo<Traveller, $this> */
    public function traveller(): BelongsTo
    {
        return $this->belongsTo(Traveller::class);
    }

    /** What it was allowed to read, for auditing an answer against it. */
    public function sourceTitles(): string
    {
        return collect($this->sources ?? [])
            ->map(fn (array $source): string => (string) ($source['title'] ?? ''))
            ->filter()
            ->join(', ');
    }
}
