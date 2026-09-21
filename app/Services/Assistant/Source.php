<?php

namespace App\Services\Assistant;

use App\Support\StudyPlanItem;

/**
 * One scholar-approved passage the assistant is allowed to answer from.
 *
 * A class rather than an array shape, for the reason {@see StudyPlanItem}
 * records: a `Collection`'s value template is invariant.
 *
 * Every source here has been through the editorial gate — approved by a
 * named reviewer and published. Nothing else ever reaches the model, which
 * is §9.6's "strictly from scholar-approved Knowledge Centre content"
 * implemented as a query rather than as an instruction in a prompt.
 */
final class Source
{
    public function __construct(
        /** 'knowledge', 'ziyarah' or 'learning'. */
        public readonly string $kind,
        public readonly string $kindLabel,
        public readonly string $title,
        /** Where the pilgrim reads it in full, so a citation can be checked. */
        public readonly string $url,
        /** The passage put in front of the model, and nothing beyond it. */
        public readonly string $excerpt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'url' => $this->url,
        ];
    }
}
