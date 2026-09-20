<?php

namespace App\Services\Assistant;

use Illuminate\Support\Collection;

/**
 * What the assistant came back with — §9.6.
 *
 * ## There are two outcomes, and one of them is "ask a person"
 *
 * §9.6 requires "a hard 'I'll connect you to an advisor' fallback", and
 * hard is the operative word: the fallback is not a message the model is
 * asked to produce when it feels unsure. It is what this class *is*
 * whenever the constraints in {@see PilgrimAssistant} are not met, and the
 * model is not called at all in most of those cases.
 *
 * A referral always carries the reason, because "I cannot help" with no
 * explanation reads as a broken website rather than a deliberate limit.
 */
final class Answer
{
    private function __construct(
        public readonly bool $referred,
        public readonly string $text,
        /** @var Collection<int, Source> */
        public readonly Collection $sources,
        /** Why it would not answer. Null when it did. */
        public readonly ?string $because,
    ) {}

    /**
     * @param  Collection<int, Source>  $sources
     */
    public static function answered(string $text, Collection $sources): self
    {
        return new self(false, $text, $sources, null);
    }

    /**
     * Hand it to a human, and say why.
     *
     * @param  Collection<int, Source>|null  $sources  What was found, if anything —
     *                                                 worth showing even when it did
     *                                                 not answer, because the pilgrim
     *                                                 may well want to read it.
     */
    public static function referToAdvisor(string $because, ?Collection $sources = null): self
    {
        return new self(
            referred: true,
            text: __('messages.I cannot answer this from what our scholars have approved, so I will not guess. Send it to them and somebody will come back to you.'),
            sources: $sources ?? collect(),
            because: $because,
        );
    }

    /** The label §9.6 requires on anything a model wrote. */
    public function label(): string
    {
        return __('messages.AI-assisted, from our scholars\' own words');
    }
}
