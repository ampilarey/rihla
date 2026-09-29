<?php

namespace App\Services\Hosts;

use App\Exceptions\PayoutRefused;
use App\Models\HostStatement;
use App\Models\Payout;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recording a transfer to a host — §16.9, §16 Phase 16.
 *
 * Against a statement, because the statement is what says how much Rihla
 * holds for the host. Refused rather than recorded when it would not be
 * true: more than is owed, in another currency, or to a host who has not
 * said where to send it. Under a lock on the statement, so two people
 * recording the same transfer at once cannot both succeed and overpay.
 */
class Payouts
{
    public function record(HostStatement $statement, Money $amount, CarbonImmutable $paidOn, string $reference, ?User $by = null): Payout
    {
        return DB::transaction(function () use ($statement, $amount, $paidOn, $reference, $by): Payout {
            $statement = HostStatement::query()->with('partner')->lockForUpdate()->findOrFail($statement->getKey());
            $host = $statement->partner;

            if ($host === null || ! $host->hasPayoutDetails()) {
                throw new PayoutRefused('The host has not given the bank account to pay into.');
            }

            if ($amount->currency !== $statement->currency) {
                throw new PayoutRefused('This statement is in '.$statement->currency.'; a payout against it is too.');
            }

            if ($amount->minor <= 0) {
                throw new PayoutRefused('A payout is more than nothing.');
            }

            $owed = $statement->stillOwed();

            if ($amount->minor > $owed->minor) {
                throw new PayoutRefused('Only '.$owed->format().' is still owed on this statement.');
            }

            if (trim($reference) === '') {
                throw new PayoutRefused('Give the bank\'s reference for the transfer.');
            }

            $payout = new Payout;
            $payout->forceFill([
                'partner_id' => $host->getKey(),
                'host_statement_id' => $statement->getKey(),
                'amount_minor' => $amount->minor,
                'currency' => $amount->currency,
                'paid_on' => $paidOn->toDateString(),
                'reference' => trim($reference),
                'recorded_by' => $by?->getKey(),
            ])->save();

            return $payout;
        });
    }
}
