<?php

namespace App\Support;

use App\Models\Customer;
use Carbon\CarbonInterface;

/**
 * One person who has sent Rihla business, and what it came to.
 *
 * A class rather than an array shape, for the reason {@see StudyPlanItem}
 * records: a `Collection`'s value template is invariant, so an array shape
 * inside one cannot be handed back from a typed method.
 *
 * ## It does not claim to know whether anybody thanked them
 *
 * A thank-you is a telephone call, and nothing in this application can see
 * one. What it can see is whether anybody **wrote anything down** about
 * this person since their last referral arrived, and that is what
 * {@see lastNoted} holds and what the screen says. Storing a "thanked"
 * flag would mean somebody ticking a box, and a ticked box is evidence
 * that a box was ticked.
 */
final class ReferralCredit
{
    public function __construct(
        public readonly Customer $referrer,
        /** Everybody who named this person as their referrer. */
        public readonly int $referred,
        /** How many of those went on to actually travel. */
        public readonly int $travelled,
        /** Seats those journeys accounted for — the size of the favour. */
        public readonly int $seats,
        /** When the most recent of them flew, or null if none has. */
        public readonly ?CarbonInterface $lastArrival,
        /**
         * When anybody last recorded a follow-up task about this person.
         *
         * Not "when they were last thanked" — see the class docblock.
         */
        public readonly ?CarbonInterface $lastNoted,
    ) {}

    /**
     * Nothing has been written down since their last referral travelled.
     *
     * The one row the office should act on: somebody sent Rihla a pilgrim,
     * that pilgrim has been and come back, and there is no record of anyone
     * having said a word to them about it.
     */
    public function isUnacknowledged(): bool
    {
        if ($this->lastArrival === null) {
            return false;
        }

        return $this->lastNoted === null || $this->lastNoted->lt($this->lastArrival);
    }

    /** "3 people, 2 of whom travelled" — the favour in words. */
    public function spoken(): string
    {
        $sent = $this->referred.' '.($this->referred === 1 ? 'person' : 'people');

        if ($this->travelled === 0) {
            return $sent.', none of whom has travelled yet';
        }

        if ($this->travelled === $this->referred) {
            return $sent.', '.($this->referred === 1 ? 'who travelled' : 'all of whom travelled')
                .' — '.$this->seats.' '.($this->seats === 1 ? 'seat' : 'seats').' in all';
        }

        return $sent.', '.$this->travelled.' of whom travelled — '
            .$this->seats.' '.($this->seats === 1 ? 'seat' : 'seats').' in all';
    }

    public function tone(): string
    {
        return $this->isUnacknowledged() ? 'warning' : 'success';
    }
}
