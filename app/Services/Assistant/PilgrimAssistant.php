<?php

namespace App\Services\Assistant;

use App\Models\AssistantExchange;
use App\Models\Traveller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The pilgrim assistant — §9.6, and mostly the half of §9.6 that says no.
 *
 * §9.6 asks for an assistant that answers "**strictly from scholar-approved
 * Knowledge Centre content**, with citations and a hard 'I'll connect you
 * to an advisor' fallback", and ends: "**Never let it improvise rulings.**"
 *
 * ## The constraints are code, not prompt wording
 *
 * A system prompt saying "only use the sources below" is a request. A model
 * that ignores it is not malfunctioning, it is doing what models do. So
 * every constraint §9.6 names is enforced here, outside the model:
 *
 * 1. **Only approved content is retrieved** — {@see Corpus} queries through
 *    the editorial gate's `live()` scope and cannot see a draft.
 * 2. **No matching source means no model call at all.** The question goes
 *    straight to a human. This is the whole of the behaviour today, because
 *    nothing has been approved: no reviewer has been named.
 * 3. **No provider configured means no model call.** Also the whole of the
 *    behaviour today.
 * 4. **An answer that cites nothing is thrown away.** The model is asked to
 *    mark each claim with the source number it came from, and a reply with
 *    no marker is discarded and replaced with the referral — because an
 *    uncited sentence about a rite is exactly the improvised ruling §9.6
 *    forbids, and it is indistinguishable from a good one by reading it.
 * 5. **The asker is never named in the prompt** (§9.6: no personal data in
 *    prompts). The exchange log knows who asked; the model does not.
 *
 * ## Today it answers nothing, and that is correct
 *
 * The Knowledge Centre, the Ziyarah Guide and the Learning Academy are all
 * empty of approved content, because approval needs a named scholar and
 * nobody has named one. Every question therefore takes route 2 above. That
 * is the feature working: an assistant that started answering the moment a
 * key was pasted in, from a corpus of nothing, is the failure this class is
 * shaped to prevent.
 */
final class PilgrimAssistant
{
    public function __construct(private readonly Provider $provider) {}

    public static function make(): self
    {
        return new self(match ((string) config('assistant.provider')) {
            'anthropic' => new AnthropicProvider,
            default => new NotConfigured,
        });
    }

    /**
     * @param  Traveller|null  $asker  Logged against the exchange, never sent
     *                                 to the model.
     */
    public function ask(string $question, ?Traveller $asker = null): Answer
    {
        $question = trim($question);

        if ($question === '') {
            return $this->record($question, $asker, Answer::referToAdvisor(
                'An empty question has nothing to look up.',
            ));
        }

        if (config('assistant.enabled') !== true) {
            return $this->record($question, $asker, Answer::referToAdvisor(
                'The assistant is switched off. Every question goes to a person, which is the same answer it would give if it could not find an approved source.',
            ));
        }

        $sources = Corpus::matching($question);

        // Constraint 2. Note the order: this is checked *before* the
        // provider, so that a configured key against an empty corpus still
        // refuses, rather than refusing for the wrong reason.
        if ($sources->isEmpty()) {
            return $this->record($question, $asker, Answer::referToAdvisor(
                'Nothing a scholar has approved covers this question. Answering from anything else would be guessing, and this assistant does not guess about religion.',
            ));
        }

        // Constraint 3.
        if (! $this->provider->isConfigured()) {
            return $this->record($question, $asker, Answer::referToAdvisor(
                'No assistant has been set up for this site yet, so this goes to a person.',
                $sources,
            ));
        }

        $reply = $this->provider->complete($this->systemPrompt($sources), $question);

        if ($reply === null) {
            return $this->record($question, $asker, Answer::referToAdvisor(
                'The assistant could not be reached just now, so this goes to a person.',
                $sources,
            ));
        }

        // Constraint 4. An uncited sentence about a rite is the improvised
        // ruling §9.6 forbids, and reading it will not tell you which it is.
        if (! self::citesASource($reply, $sources->count())) {
            return $this->record($question, $asker, Answer::referToAdvisor(
                'The assistant answered without pointing at an approved source, so the answer was discarded rather than shown.',
                $sources,
            ));
        }

        return $this->record($question, $asker, Answer::answered($reply, $sources));
    }

    /**
     * Whether the reply points at one of the passages it was given.
     *
     * `[1]`…`[n]` only, and only numbers that exist: a model that invents
     * `[7]` out of four sources has invented the citation too.
     */
    public static function citesASource(string $reply, int $sourceCount): bool
    {
        if ($sourceCount < 1) {
            return false;
        }

        preg_match_all('/\[(\d{1,2})\]/', $reply, $matches);

        foreach ($matches[1] as $number) {
            if ((int) $number >= 1 && (int) $number <= $sourceCount) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Source>  $sources
     */
    private function systemPrompt(Collection $sources): string
    {
        $passages = $sources
            ->map(fn (Source $source, int $index): string => '['.($index + 1).'] '
                .$source->kindLabel.' — '.$source->title."\n".$source->excerpt)
            ->join("\n\n");

        // The wording still matters even though the guarantees do not rest
        // on it: a model told plainly what it may not do refuses more often
        // and more gracefully, which means fewer answers thrown away by
        // constraint 4 and fewer pilgrims sent to a person unnecessarily.
        return <<<PROMPT
        You are answering a question for a Maldivian pilgrim preparing for Umrah, on behalf of Rihla Travels.

        Answer ONLY from the numbered passages below. They have each been approved by a named scholar; nothing else you know may be used, however confident you are of it.

        Rules:
        - Mark every claim with the number of the passage it came from, like [1] or [2]. An answer with no such marker will be thrown away by the software and the pilgrim will be sent to a human instead.
        - If the passages do not answer the question, say so plainly and do not fill the gap. Saying "our scholars have not written about this" is a correct and useful answer.
        - Never issue a ruling of your own, never reason from analogy to a case the passages do not cover, and never give medical or legal advice.
        - Do not quote or paraphrase scripture that is not in the passages.
        - Be brief. Two or three short paragraphs at most, in plain language.

        The approved passages:

        {$passages}
        PROMPT;
    }

    /** §9.6: prompts and responses are logged. */
    private function record(string $question, ?Traveller $asker, Answer $answer): Answer
    {
        AssistantExchange::create([
            'traveller_id' => $asker?->getKey(),
            'question' => Str::limit($question, 4000, ''),
            'answer' => Str::limit($answer->text, 8000, ''),
            'referred' => $answer->referred,
            'reason' => $answer->because,
            'sources' => $answer->sources->map(fn (Source $source): array => $source->toArray())->all(),
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
            'locale' => app()->getLocale(),
        ]);

        return $answer;
    }
}
