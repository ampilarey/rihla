<?php

namespace App\Services\Stays;

use App\Models\Partner;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * What a guest owes at the desk — §16.10.
 *
 * The room line and the Green Tax line are **read from the stay's snapshot**
 * every time, not copied into `stay_charges`: the snapshot is already the
 * record of what was agreed, and a second copy is a second thing that can
 * disagree with it. The confirmation PDF prints the room from the same
 * snapshot, so the two documents cannot drift apart. Only what the host
 * adds — extras, discounts, adjustments — is stored.
 *
 * Balance = charges − payments, from **both** collectors: the guest does not
 * care whose account the deposit went to, only what is left to pay.
 */
final class StayBill
{
    /** @var list<array{description: string, kind: string, total: Money}> */
    private array $lines = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(public readonly Stay $stay)
    {
        $this->lines[] = [
            'description' => sprintf(
                '%s · %s – %s, %d night%s',
                (string) $stay->roomType?->name,
                $stay->check_in->format('j M'),
                $stay->check_out->format('j M Y'),
                $stay->nights,
                $stay->nights === 1 ? '' : 's',
            ).(isset($stay->rate_snapshot['discount']['name'], $stay->rate_snapshot['discount']['percent'])
                // §16 Phase 16: the discount it was sold with, named on the bill.
                ? sprintf(' · %s, %d%% off', $stay->rate_snapshot['discount']['name'], $stay->rate_snapshot['discount']['percent'])
                : ''),
            'kind' => StayCharge::ROOM,
            'total' => $stay->total(),
        ];

        $tax = (array) ($stay->rate_snapshot['green_tax'] ?? []);

        // Collected at the property: it is the host's to take, so it is on
        // the host's bill. Included in the rate: it is already in the room
        // line, and a second line would charge it twice.
        if (($tax['applies'] ?? false) && ($tax['mode'] ?? null) === Partner::GREEN_TAX_AT_PROPERTY && isset($tax['total_minor'], $tax['currency'])) {
            $green = Money::ofMinor((int) $tax['total_minor'], (string) $tax['currency']);

            if ($green->currency === $stay->currency) {
                $this->lines[] = ['description' => 'Green Tax', 'kind' => StayCharge::GREEN_TAX, 'total' => $green];
            } else {
                // Never summed across currencies. Said on the bill instead.
                $this->notes[] = 'Green Tax of '.$green->format().' is paid separately, in '.$green->currency.'.';
            }
        }

        foreach ($stay->charges as $charge) {
            $this->lines[] = [
                'description' => (string) $charge->description,
                'kind' => $charge->kind,
                'total' => $charge->total(),
            ];
        }
    }

    public static function for(Stay $stay): self
    {
        return new self($stay->loadMissing(['roomType', 'charges']));
    }

    /** @return list<array{description: string, kind: string, total: Money}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function total(): Money
    {
        $minor = array_sum(array_map(fn (array $line): int => $line['total']->minor, $this->lines));

        return Money::ofMinor($minor, $this->stay->currency);
    }

    public function paid(): Money
    {
        return $this->stay->paid();
    }

    /**
     * The bill as a PDF, issued in the host's name — §16.10.
     *
     * The host's registration in the header and Rihla's line in the footer;
     * never stored, because the stay is the record and this is a view of it.
     */
    public function pdf(): string
    {
        $stay = $this->stay->loadMissing(['customer', 'property.partner', 'unit', 'payments']);
        $host = $stay->property->partner;

        return Pdf::loadView('pdf.stay-bill', [
            'stay' => $stay,
            'bill' => $this,
            'issuer' => [
                'name' => (string) ($host->name ?? $stay->property->name),
                'address' => collect([$stay->property->island, $stay->property->atoll])->filter()->implode(', ') ?: null,
                'registration' => $host->registration_number ?? null,
                'phone' => (string) ($host->phone ?? ''),
                'email' => $host->email ?? null,
            ],
            'footer' => 'Issued through '.config('invoices.issuer.name', 'Rihla Travels').'.',
        ])->output();
    }

    /** Negative when the guest is in credit — shown, not hidden. */
    public function balance(): Money
    {
        return Money::ofMinor($this->total()->minor - $this->stay->paid_minor, $this->stay->currency);
    }
}
