<?php

namespace App\Services\Hosts;

use App\Models\HostStatement;
use App\Models\Partner;
use App\Models\User;
use App\Support\Contact;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly statements — §16.9. The month's {@see Earnings}, frozen.
 */
class Statements
{
    public function __construct(private readonly Earnings $earnings) {}

    /**
     * Issue a host's statements for a month, one per currency. A month
     * already issued is left as it was: a statement is a document somebody
     * may have filed, and reissuing it would quietly change it.
     *
     * @return Collection<int, HostStatement> the ones issued now
     */
    public function issue(Partner $host, CarbonImmutable $month, ?User $by = null): Collection
    {
        $start = $month->startOfMonth();
        $issued = collect();

        foreach ($this->earnings->forMonth($host, $start) as $currency => $row) {
            $exists = HostStatement::query()
                ->where('partner_id', $host->getKey())
                ->whereDate('period_start', $start->toDateString())
                ->where('currency', $currency)
                ->exists();

            if ($exists) {
                continue;
            }

            $statement = new HostStatement;
            $statement->forceFill([
                'partner_id' => $host->getKey(),
                'period_start' => $start->toDateString(),
                'period_end' => $start->endOfMonth()->toDateString(),
                'currency' => $currency,
                'marketplace_count' => $row['marketplace_count'],
                'gross_minor' => $row['marketplace_gross']->minor,
                'commission_minor' => $row['commission']->minor,
                'net_minor' => $row['host_net']->minor,
                'direct_count' => $row['direct_count'],
                'direct_gross_minor' => $row['direct_gross']->minor,
                'paid_to_rihla_minor' => $row['paid_to_rihla']->minor,
                'paid_here_minor' => $row['paid_here']->minor,
                'rihla_holds_minor' => $row['rihla_holds_for_host']->minor,
                'commission_outstanding_minor' => $row['commission_outstanding']->minor,
                'reference' => sprintf('RIH-HS-%s-%d-%s', $start->format('Ym'), $host->getKey(), $currency),
                'issued_at' => now(),
                'issued_by' => $by?->getKey(),
            ])->save();

            $issued->push($statement);
        }

        return $issued;
    }

    /** Every active host's statements for a month — the scheduled run. */
    public function issueForAll(CarbonImmutable $month): int
    {
        $count = 0;

        Partner::query()->where('status', Partner::STATUS_ACTIVE)->each(function (Partner $host) use ($month, &$count): void {
            $count += $this->issue($host, $month)->count();
        });

        return $count;
    }

    /** The statement as a PDF — `Brand` colours only; a host's never enter a PDF. */
    public function pdf(HostStatement $statement): string
    {
        $host = $statement->partner;

        return Pdf::loadView('pdf.host-statement', [
            'statement' => $statement,
            'issuer' => [
                'name' => (string) config('invoices.issuer.name', 'Rihla Travels'),
                'address' => config('invoices.issuer.address'),
                'registration' => config('invoices.issuer.registration'),
                'phone' => Contact::displayNumber(),
                'email' => config('invoices.issuer.email'),
            ],
            'host' => $host,
        ])->output();
    }
}
