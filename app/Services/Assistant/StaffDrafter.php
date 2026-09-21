<?php

namespace App\Services\Assistant;

use App\Models\AssistantExchange;
use Illuminate\Support\Str;

/**
 * The staff drafting assistant — §9.6's second of two.
 *
 * §9.6: "quotations, itinerary text, announcement translation (en/dv/ar)
 * **with human review before send**."
 *
 * ## It drafts. It does not translate.
 *
 * The translation half of that sentence is deliberately not built, and this
 * is the one place in the codebase where refusing a feature needs stating
 * rather than implying.
 *
 * `AGENTS.md` records what machine-generated Dhivehi has already cost this
 * site: fabricated entries were found in `resources/lang/dv/` and in the
 * seeded Umrah guide, always with the same signature — many unrelated
 * English strings mapping to one identical Dhivehi string. They reached
 * pilgrims. `TranslationQualityTest` fails if that pattern returns, and the
 * standing instruction is that Dhivehi is "being removed rather than
 * trusted", with religious text never paraphrased to fill a gap.
 *
 * Adding a button that produces Dhivehi nobody in the office can check is
 * the same mistake with a nicer interface. When Rihla has a Dhivehi speaker
 * reviewing output before it ships, this becomes a small change; until
 * then it is not a missing feature, it is a declined one.
 *
 * ## Everything it produces is a draft, and only a draft
 *
 * Nothing here sends, publishes, quotes a price or reaches a customer.
 * The output goes into a form field for a member of staff to edit, and the
 * §9.6 label rides with it. There is no path from this class to a pilgrim
 * that does not pass through a person pressing save.
 *
 * ## No customer's details go to the model
 *
 * §9.6 prohibits personal data in prompts. The caller passes notes, and
 * {@see draft()} is never handed a customer, a traveller or a booking —
 * the same line {@see PilgrimAssistant} holds, held the same way: by not
 * having the data to leak.
 */
final class StaffDrafter
{
    /** A reply to an enquiry somebody still has to read and send. */
    public const ENQUIRY_REPLY = 'enquiry_reply';

    /** Itinerary prose from a day's bare facts. */
    public const ITINERARY = 'itinerary';

    /** An announcement for a departure, in English. */
    public const ANNOUNCEMENT = 'announcement';

    /** @var list<string> */
    public const KINDS = [self::ENQUIRY_REPLY, self::ITINERARY, self::ANNOUNCEMENT];

    public function __construct(private readonly Provider $provider) {}

    public static function make(): self
    {
        return new self(match ((string) config('assistant.provider')) {
            'anthropic' => new AnthropicProvider,
            default => new NotConfigured,
        });
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::ENQUIRY_REPLY => 'A reply to an enquiry',
            self::ITINERARY => 'Itinerary wording',
            self::ANNOUNCEMENT => 'An announcement',
            default => 'A draft',
        };
    }

    /**
     * A first draft, or null when it cannot produce one.
     *
     * Null rather than an apologetic placeholder: a member of staff who
     * sees an empty box knows to write it themselves, where one who sees
     * "I could not generate this" may paste it.
     *
     * @param  string  $notes  The facts to write from. The caller is
     *                         responsible for these carrying no customer
     *                         details, and the screens that call this say so.
     */
    public function draft(string $kind, string $notes): ?string
    {
        $notes = trim($notes);

        if ($notes === '' || ! in_array($kind, self::KINDS, true)) {
            return null;
        }

        if (config('assistant.enabled') !== true || ! $this->provider->isConfigured()) {
            return null;
        }

        $text = $this->provider->complete($this->systemPrompt($kind), $notes);

        $this->record($kind, $notes, $text);

        return $text;
    }

    /** Why there is no draft, for the screen to print instead of one. */
    public function whyNotAvailable(): ?string
    {
        if (config('assistant.enabled') !== true) {
            return 'The drafting assistant is switched off for this site.';
        }

        if (! $this->provider->isConfigured()) {
            return 'No assistant has been set up for this site yet — it needs an API key, which nobody has supplied.';
        }

        return null;
    }

    /**
     * Whether this drafter would refuse a Dhivehi request.
     *
     * Always true, and it is a method rather than a silent absence so that
     * a screen can say why rather than leaving somebody to wonder where the
     * button went. See the class docblock.
     */
    public static function refusesTranslation(): bool
    {
        return true;
    }

    public static function whyNoTranslation(): string
    {
        return 'This will not translate into Dhivehi. Machine-generated Dhivehi has already reached this site once — '
            .'many unrelated English sentences mapping to one identical Dhivehi string — and it was found by a reader, '
            .'not by a test. Until somebody in the office can check Dhivehi before it ships, the honest thing is to fall '
            .'back to English rather than produce something nobody can read.';
    }

    private function systemPrompt(string $kind): string
    {
        $shared = <<<'SHARED'
        You are drafting text for a member of staff at Rihla Travels, a Maldivian Umrah operator. What you write is a FIRST DRAFT that a person will read, edit and decide whether to use. It is never sent as it stands.

        Rules that hold for every draft:
        - Write in plain British English. No marketing language, no exclamation marks.
        - Invent nothing. If a fact is not in the notes — a price, a date, a hotel name, a flight — leave a clearly marked gap like [price] rather than filling it.
        - Never give a religious ruling, and never describe how a rite is performed. Rihla's scholars write that, and it goes through review.
        - Never give medical or legal advice.
        - Do not translate into Dhivehi or Arabic.
        SHARED;

        return $shared."\n\n".match ($kind) {
            self::ENQUIRY_REPLY => 'Draft a short reply to somebody who has enquired. Answer what the notes answer, say plainly what still needs checking, and end with one clear next step. Four short paragraphs at most.',
            self::ITINERARY => 'Turn the notes into itinerary wording for one day, as a pilgrim would read it. Concrete and calm; times only where the notes give them.',
            self::ANNOUNCEMENT => 'Draft an announcement for the pilgrims on one departure. Lead with what they have to do or know; keep it under 120 words.',
            default => 'Draft the text the notes describe.',
        };
    }

    /**
     * §9.6: prompts and responses are logged, staff drafts included.
     *
     * The same table as the pilgrim assistant's, with no traveller against
     * it: nobody asked this, a member of staff did, and the log exists to
     * show what the model was used for rather than who asked.
     */
    private function record(string $kind, string $notes, ?string $text): void
    {
        AssistantExchange::create([
            'traveller_id' => null,
            'question' => '['.$kind.'] '.Str::limit($notes, 3900, ''),
            'answer' => Str::limit((string) $text, 8000, ''),
            'referred' => $text === null,
            'reason' => $text === null ? 'The provider returned nothing, so the member of staff writes it themselves.' : null,
            'sources' => [],
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
            'locale' => app()->getLocale(),
        ]);
    }
}
