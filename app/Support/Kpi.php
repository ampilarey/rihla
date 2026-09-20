<?php

namespace App\Support;

/**
 * One measure on the executive dashboard — §8.5, §10.5.
 *
 * ## Three states, not two
 *
 * A dashboard that can only say "here is a number" has to say something
 * when it has no number, and what it usually says is `0`. A zero conversion
 * rate and no enquiries at all look identical on a tile and mean opposite
 * things: one is a business in trouble, the other is a quiet fortnight.
 *
 * So a KPI is in one of three states and says which:
 *
 * - {@see MEASURED} — there is a figure, and it is arithmetic over real rows.
 * - {@see NOTHING_TO_MEASURE} — the measure is real and the input set is
 *   empty. No enquiries arrived; no departure has flown; no learning module
 *   has been published. The answer is "not yet", and it carries the reason.
 * - {@see NOT_INSTRUMENTED} — nothing in this application records what the
 *   measure needs. Four of §10.5's KPIs are in this state today, and the
 *   honest thing is to name them rather than substitute a proxy that looks
 *   like the real thing. A "portal weekly-active" figure derived from a
 *   last-seen stamp is not a weekly-active figure; it is a number somebody
 *   would report to a bank.
 *
 * The figure is formatted here rather than in the view, so the rule about
 * what "23%" means when the denominator is four lives next to the
 * arithmetic that produced it.
 */
final class Kpi
{
    public const MEASURED = 'measured';

    public const NOTHING_TO_MEASURE = 'nothing_to_measure';

    public const NOT_INSTRUMENTED = 'not_instrumented';

    private function __construct(
        public readonly string $key,
        public readonly string $name,
        /** The plain question it answers, in the words somebody would ask it. */
        public readonly string $question,
        public readonly string $state,
        /** Already formatted for display — "23%", "4 hours", "MVR 1,240". */
        public readonly ?string $figure,
        /** The arithmetic in words: "23 of 104 enquiries became a booking". */
        public readonly ?string $detail,
        /** Why there is no figure. Never null unless there is one. */
        public readonly ?string $because,
        /** success / warning / danger / gray — judged where the direction is known. */
        public readonly string $tone,
        public readonly ?string $target,
    ) {}

    public static function measured(
        string $key,
        string $name,
        string $question,
        string $figure,
        ?string $detail = null,
        string $tone = 'gray',
        ?string $target = null,
    ): self {
        return new self($key, $name, $question, self::MEASURED, $figure, $detail, null, $tone, $target);
    }

    public static function nothingToMeasure(string $key, string $name, string $question, string $because): self
    {
        return new self($key, $name, $question, self::NOTHING_TO_MEASURE, null, null, $because, 'gray', null);
    }

    public static function notInstrumented(string $key, string $name, string $question, string $because): self
    {
        return new self($key, $name, $question, self::NOT_INSTRUMENTED, null, null, $because, 'gray', null);
    }

    public function hasFigure(): bool
    {
        return $this->state === self::MEASURED;
    }

    /**
     * What to call this state on screen.
     *
     * "Not measured yet" and "Nothing to measure" are deliberately
     * different phrases: the first is a gap in the software, the second is
     * a gap in the month.
     */
    public function stateLabel(): string
    {
        return match ($this->state) {
            self::NOTHING_TO_MEASURE => 'Nothing to measure yet',
            self::NOT_INSTRUMENTED => 'Not measured — nothing records this',
            default => 'Measured',
        };
    }

    /** A whole-number percentage, or null when the denominator is zero. */
    public static function percentOf(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : round($part / $whole * 100).'%';
    }

    /**
     * A duration a human reads without converting it.
     *
     * Minutes under an hour, hours and minutes under a day, days and hours
     * after that. An enquiry answered in "0.3 days" is an enquiry nobody
     * can picture, and "90 minutes" rounded to "2 hours" overstates the
     * wait by a third in exactly the range the office argues about.
     */
    public static function duration(float $minutes): string
    {
        $whole = max(0, (int) round($minutes));

        if ($whole < 60) {
            return self::plural($whole, 'minute');
        }

        if ($whole < 1440) {
            $hours = intdiv($whole, 60);
            $rest = $whole % 60;

            return self::plural($hours, 'hour').($rest > 0 ? ' '.self::plural($rest, 'minute') : '');
        }

        $days = intdiv($whole, 1440);
        $hours = intdiv($whole % 1440, 60);

        return self::plural($days, 'day').($hours > 0 ? ' '.self::plural($hours, 'hour') : '');
    }

    private static function plural(int $count, string $noun): string
    {
        return $count.' '.($count === 1 ? $noun : $noun.'s');
    }

    /**
     * The middle value, which is the one worth reporting for a duration.
     *
     * A mean response time is moved by the single enquiry somebody found
     * three weeks later, and moved most in the month it is worst. The
     * median says what a typical enquirer actually waited.
     *
     * @param  list<float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
