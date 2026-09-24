<?php

namespace App\Services\Stays;

use App\Models\Enquiry;
use App\Models\Stay;
use App\Support\Money;

/**
 * A stay that fell through becomes somebody to ring — §15.7.
 *
 * *"So a lost stay is a follow-up rather than a silence."* Somebody asked
 * for a guesthouse and did not get one: the partner was full, or the
 * deposit window closed while they were asleep in another time zone. Left
 * alone, that is a person who wanted to give Rihla money and heard
 * nothing back.
 *
 * ## Only the two endings Rihla caused
 *
 * `declined` and `expired`. **Not `cancelled`** — the customer changed
 * their mind, and a follow-up call about a holiday somebody deliberately
 * called off is a nuisance rather than a service. The distinction is the
 * whole reason those are separate statuses.
 *
 * ## It reuses the CRM rather than inventing one
 *
 * An {@see Enquiry} already means "somebody asked, and here is who owns
 * chasing it". A second list of lost stays would be a second queue nobody
 * looks at. The enquiry carries `stay_id` and `property_id` so the first
 * sentence of the call can be *"you asked about Maafushi View"*, which is
 * the difference between a follow-up and a cold call.
 *
 * ## Unassigned on purpose
 *
 * It arrives as `new` with no owner. Guessing an owner would put it on
 * somebody's list without their knowing, and §8.1 is built on the idea
 * that an owner is a person who took it — the CRM board is where that
 * happens.
 */
class LostStayFollowUp
{
    /** Statuses worth ringing somebody about. */
    public const WORTH_A_CALL = [Stay::DECLINED, Stay::EXPIRED];

    /**
     * Record the follow-up, if this ending deserves one.
     *
     * Null when it does not, or when one already exists — a stay cannot be
     * lost twice, but a status written twice must not produce two calls to
     * the same person about the same week.
     */
    public function record(Stay $stay): ?Enquiry
    {
        if (! in_array($stay->status, self::WORTH_A_CALL, true)) {
            return null;
        }

        if (Enquiry::where('stay_id', $stay->getKey())->exists()) {
            return null;
        }

        $customer = $stay->customer;

        if ($customer === null) {
            return null;
        }

        $enquiry = Enquiry::create([
            // Where the ask came from, not where this row came from. They
            // filled in a form on the website; that it became an enquiry by
            // this route is what `stay_id` records.
            'source' => Enquiry::WEB,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'message' => $this->summary($stay),
            'party_size' => $stay->adults + $stay->children,
        ]);

        $enquiry->forceFill([
            'stay_id' => $stay->getKey(),
            'property_id' => $stay->property_id,
            'customer_id' => $customer->getKey(),
            'next_action' => $this->nextAction($stay),
            // Today, not "some time". A queue with no date on it is a list,
            // and §8.1 exists because a list is what Rihla already had.
            'next_action_at' => now()->toDateString(),
        ])->save();

        return $enquiry->refresh();
    }

    /**
     * What the person on the phone needs in front of them.
     *
     * Dates, room, party and money — the things they will be asked, so
     * nobody has to open the stay to answer a question the customer asks
     * in the first ten seconds.
     */
    private function summary(Stay $stay): string
    {
        $lines = [
            sprintf(
                '%s — %s to %s, %d night%s.',
                $stay->property?->getTranslation('name', 'en') ?: 'A guesthouse',
                $stay->check_in->format('j M Y'),
                $stay->check_out->format('j M Y'),
                $stay->nights,
                $stay->nights === 1 ? '' : 's',
            ),
            sprintf(
                '%d adult%s%s, %s.',
                $stay->adults,
                $stay->adults === 1 ? '' : 's',
                $stay->children > 0 ? ', '.$stay->children.' child'.($stay->children === 1 ? '' : 'ren') : '',
                Money::ofMinor($stay->total_minor, $stay->currency)->format(),
            ),
            $stay->status === Stay::DECLINED
                ? 'The guesthouse could not take it.'
                : 'The deposit was not paid before the hold ran out.',
        ];

        if (filled($stay->cancellation_reason)) {
            $lines[] = 'Reason given: '.$stay->cancellation_reason;
        }

        if (filled($stay->special_requests)) {
            $lines[] = 'They asked for: '.$stay->special_requests;
        }

        return implode("\n", $lines);
    }

    /**
     * The two endings need two different calls, and saying which saves
     * whoever picks it up from reading the whole thing first.
     */
    private function nextAction(Stay $stay): string
    {
        return $stay->status === Stay::DECLINED
            ? 'Offer another guesthouse for the same dates'
            : 'Ask whether they still want the room';
    }
}
