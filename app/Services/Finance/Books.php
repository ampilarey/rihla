<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\Payment;
use App\Services\Operations\ListDocument;
use App\Support\JourneyProfit;
use App\Support\Money;
use App\Support\Rates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The company books — batch C from Hijaz, as the owner chose: a cash book
 * per departure (and one for the company), with a profit and loss, from
 * the money that actually moved; the same month by month; what is still
 * owed; and what came in. Each as a screen and as a CSV.
 *
 * Nothing here is stored. Money in is the payment ledger's succeeded rows
 * — the one place money is ever recorded as received — and money out is
 * the costs Finance marked paid. A cost that is only agreed or estimated
 * is in the journey's margin ({@see JourneyProfit}) and not
 * in the cash book, because the cash book is what happened, not what is
 * expected to. Currencies are kept apart and added up only where a rate
 * is known ({@see Rates}); a figure nobody set never appears.
 */
final class Books
{
    public const CASH = 'cash';

    public const MONTHS = 'months';

    public const OWED = 'owed';

    public const COLLECTIONS = 'collections';

    public const REPORTS = [self::CASH, self::MONTHS, self::OWED, self::COLLECTIONS];

    /** @var array<string, string> */
    public const TITLES = [
        self::CASH => 'Cash book',
        self::MONTHS => 'Month by month',
        self::OWED => 'Still owed',
        self::COLLECTIONS => 'What came in',
    ];

    /**
     * Every rufiyaa that moved on a departure, or for the company, in date
     * order: payments received (and refunds, as money out) and costs paid.
     *
     * @return array{lines: list<array<string, mixed>>, totals: array<string, array{in: int, out: int}>, net: Money|null, unconvertible: list<string>}
     */
    public function cashBook(?Departure $departure, ?CarbonInterface $month = null): array
    {
        $lines = [];

        if ($departure !== null) {
            foreach ($this->paymentsOn($departure) as $payment) {
                $money = $payment->money();
                $lines[] = [
                    'on' => self::paymentDate($payment),
                    'kind' => $money->minor < 0 ? 'refund' : 'in',
                    'words' => ($money->minor < 0 ? 'Refund · ' : 'Payment · ').(string) $payment->reference
                        .' · '.(string) ($payment->booking()->reference ?? '')
                        .' · '.(string) ($payment->booking()?->customer->name ?? ''),
                    'detail' => $payment->receivedWords() ?? str_replace('_', ' ', (string) $payment->method),
                    'currency' => $money->currency,
                    'in' => $money->minor > 0 ? $money->minor : 0,
                    'out' => $money->minor < 0 ? -$money->minor : 0,
                ];
            }
        }

        $travellers = $departure === null ? 0 : self::travellersOn($departure);

        foreach ($this->paidCosts($departure) as $cost) {
            $amount = $cost->totalFor($travellers);
            $lines[] = [
                'on' => self::costDate($cost),
                'kind' => 'out',
                'words' => $cost->categoryLabel().($cost->supplier ? ' · '.$cost->supplier : '').($cost->description ? ' · '.$cost->description : ''),
                'detail' => $cost->is_per_person ? $cost->unit()->format().' × '.$travellers : $cost->basisLabel(),
                'currency' => $amount->currency,
                'in' => 0,
                'out' => $amount->minor,
            ];
        }

        if ($month !== null) {
            $start = CarbonImmutable::instance($month)->startOfMonth();
            $end = $start->endOfMonth();
            $lines = array_values(array_filter($lines, fn (array $line): bool => $line['on'] >= $start && $line['on'] <= $end));
        }

        usort($lines, fn (array $a, array $b): int => $a['on'] <=> $b['on']);

        return ['lines' => $lines] + self::totals($lines);
    }

    /**
     * The last so many months, newest first: what came in, what went back,
     * what went out and what was left, per currency and in one figure
     * where every rate is known.
     *
     * @return list<array{month: string, label: string, in: array<string, int>, refunds: array<string, int>, out: array<string, int>, net: Money|null, unconvertible: list<string>}>
     */
    public function monthly(int $months = 12): array
    {
        $months = max(1, min(36, $months));
        $first = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $start = $first->addMonths($i);
            $rows[$start->format('Y-m')] = ['month' => $start->format('Y-m'), 'label' => $start->format('M Y'), 'in' => [], 'refunds' => [], 'out' => []];
        }

        $payments = Payment::query()->succeeded()
            ->where('payable_type', (new Booking)->getMorphClass())
            ->get();

        foreach ($payments as $payment) {
            $key = self::paymentDate($payment)->format('Y-m');

            if (! isset($rows[$key])) {
                continue;
            }

            $money = $payment->money();
            $bucket = $money->minor < 0 ? 'refunds' : 'in';
            $rows[$key][$bucket][$money->currency] = ($rows[$key][$bucket][$money->currency] ?? 0) + abs($money->minor);
        }

        $travellersByDeparture = [];

        foreach (DepartureCost::query()->where('status', DepartureCost::PAID)->get() as $cost) {
            $key = self::costDate($cost)->format('Y-m');

            if (! isset($rows[$key])) {
                continue;
            }

            $travellers = 0;

            if ($cost->departure_id !== null) {
                $travellersByDeparture[$cost->departure_id] ??= self::travellersOn($cost->departure_id);
                $travellers = $travellersByDeparture[$cost->departure_id];
            }

            $amount = $cost->totalFor($travellers);
            $rows[$key]['out'][$amount->currency] = ($rows[$key]['out'][$amount->currency] ?? 0) + $amount->minor;
        }

        $result = [];

        foreach (array_reverse($rows) as $row) {
            $perCurrency = [];

            foreach (array_unique([...array_keys($row['in']), ...array_keys($row['refunds']), ...array_keys($row['out'])]) as $currency) {
                $perCurrency[$currency] = ($row['in'][$currency] ?? 0) - ($row['refunds'][$currency] ?? 0) - ($row['out'][$currency] ?? 0);
            }

            [$net, $unconvertible] = self::inBase($perCurrency);
            $result[] = $row + ['net' => $net, 'unconvertible' => $unconvertible];
        }

        return $result;
    }

    /**
     * Every live booking still owing money, soonest departure first.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function outstanding(): array
    {
        $bookings = Booking::query()
            ->whereIn('status', [Booking::HELD, Booking::CONFIRMED, Booking::COMPLETED])
            ->whereColumn('total_minor', '>', 'paid_minor')
            ->with(['customer', 'departure.package'])
            ->get()
            ->sortBy([fn (Booking $a, Booking $b): int => [$a->departure?->date_start, $b->balance()->minor] <=> [$b->departure?->date_start, $a->balance()->minor]]);

        $rows = [];
        $totals = [];

        foreach ($bookings as $booking) {
            $balance = $booking->balance();
            $totals[$balance->currency] = ($totals[$balance->currency] ?? 0) + $balance->minor;
            $rows[] = [
                'reference' => (string) $booking->reference,
                'customer' => (string) ($booking->customer->name ?? ''),
                'departure' => (string) ($booking->departure?->package->title ?? 'Departure'),
                'leaves' => $booking->departure?->date_start,
                'status' => (string) $booking->status,
                'total' => $booking->total(),
                'paid' => $booking->paid(),
                'balance' => $balance,
            ];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * What came in between two dates: every payment received, with what
     * arrived when it was not the booking's currency.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, byMethod: array<string, array<string, int>>}
     */
    public function collections(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->endOfDay();

        $payments = Payment::query()->succeeded()
            ->where('payable_type', (new Booking)->getMorphClass())
            ->with(['payable.customer', 'reviewer'])
            ->get()
            ->filter(fn (Payment $payment): bool => self::paymentDate($payment)->between($from, $to))
            ->sortBy(fn (Payment $payment) => self::paymentDate($payment))
            ->values();

        $rows = [];
        $totals = [];
        $byMethod = [];

        foreach ($payments as $payment) {
            $money = $payment->money();
            $totals[$money->currency] = ($totals[$money->currency] ?? 0) + $money->minor;
            $byMethod[(string) $payment->method][$money->currency] = ($byMethod[(string) $payment->method][$money->currency] ?? 0) + $money->minor;
            $rows[] = [
                'on' => self::paymentDate($payment),
                'reference' => (string) $payment->reference,
                'booking' => (string) ($payment->booking()->reference ?? ''),
                'customer' => (string) ($payment->booking()?->customer->name ?? ''),
                'method' => str_replace('_', ' ', (string) $payment->method),
                'received' => $payment->receivedWords() ?? $money->format(),
                'credited' => $money,
                'verified_by' => (string) ($payment->reviewer->name ?? ''),
            ];
        }

        return ['rows' => $rows, 'totals' => $totals, 'byMethod' => $byMethod];
    }

    /**
     * A report as a document for the CSV route — every figure as words,
     * because a spreadsheet reads "MVR 10,023.00" and a column of minor
     * units reads as nothing.
     *
     * @param  array<string, mixed>  $options
     */
    public function document(string $report, array $options = []): ListDocument
    {
        return match ($report) {
            self::CASH => $this->cashDocument($options),
            self::MONTHS => $this->monthsDocument((int) ($options['months'] ?? 12)),
            self::OWED => $this->owedDocument(),
            self::COLLECTIONS => $this->collectionsDocument(
                CarbonImmutable::parse((string) ($options['from'] ?? now()->startOfMonth()->toDateString())),
                CarbonImmutable::parse((string) ($options['to'] ?? now()->toDateString())),
            ),
            default => throw new InvalidArgumentException('No such report: '.$report),
        };
    }

    // ── Documents ────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $options */
    private function cashDocument(array $options): ListDocument
    {
        $departure = isset($options['departure']) && (int) $options['departure'] > 0
            ? Departure::query()->with('package')->find((int) $options['departure'])
            : null;
        $month = filled($options['month'] ?? null) ? CarbonImmutable::parse((string) $options['month'].'-01') : null;
        $book = $this->cashBook($departure, $month);

        $rows = array_map(fn (array $line): array => [
            'on' => $line['on']->format('Y-m-d'),
            'words' => $line['words'],
            'detail' => $line['detail'],
            'currency' => $line['currency'],
            'in' => $line['in'] > 0 ? Money::ofMinor($line['in'], $line['currency'])->format() : '',
            'out' => $line['out'] > 0 ? Money::ofMinor($line['out'], $line['currency'])->format() : '',
        ], $book['lines']);

        foreach ($book['totals'] as $currency => $total) {
            $rows[] = ['on' => '', 'words' => 'Total '.$currency, 'detail' => '', 'currency' => $currency, 'in' => Money::ofMinor($total['in'], $currency)->format(), 'out' => Money::ofMinor($total['out'], $currency)->format()];
        }

        return new ListDocument(
            title: 'Cash book — '.self::subjectLabel($departure),
            subtitle: $month === null ? 'Everything that moved' : $month->format('F Y'),
            columns: ['on' => 'Date', 'words' => 'What', 'detail' => 'Detail', 'currency' => 'Currency', 'in' => 'In', 'out' => 'Out'],
            rows: $rows,
            filename: 'cash-book-'.($departure === null ? 'company' : 'departure-'.$departure->getKey()).($month === null ? '' : '-'.$month->format('Y-m')),
        );
    }

    private function monthsDocument(int $months): ListDocument
    {
        $rows = [];

        foreach ($this->monthly($months) as $row) {
            $rows[] = [
                'month' => $row['label'],
                'in' => self::perCurrencyWords($row['in']),
                'refunds' => self::perCurrencyWords($row['refunds']),
                'out' => self::perCurrencyWords($row['out']),
                'net' => $row['net']?->format() ?? ('no rate for '.implode(', ', $row['unconvertible'])),
            ];
        }

        return new ListDocument(
            title: 'Month by month',
            subtitle: 'The last '.$months.' months',
            columns: ['month' => 'Month', 'in' => 'Came in', 'refunds' => 'Refunded', 'out' => 'Paid out', 'net' => 'Net'],
            rows: $rows,
            filename: 'books-by-month',
        );
    }

    private function owedDocument(): ListDocument
    {
        $owed = $this->outstanding();
        $rows = array_map(fn (array $row): array => [
            'reference' => $row['reference'],
            'customer' => $row['customer'],
            'departure' => $row['departure'],
            'leaves' => $row['leaves']?->format('Y-m-d') ?? '',
            'status' => $row['status'],
            'total' => $row['total']->format(),
            'paid' => $row['paid']->format(),
            'balance' => $row['balance']->format(),
        ], $owed['rows']);

        foreach ($owed['totals'] as $currency => $total) {
            $rows[] = ['reference' => '', 'customer' => 'Total '.$currency, 'departure' => '', 'leaves' => '', 'status' => '', 'total' => '', 'paid' => '', 'balance' => Money::ofMinor($total, $currency)->format()];
        }

        return new ListDocument(
            title: 'Still owed',
            subtitle: 'Every live booking with a balance, soonest departure first',
            columns: ['reference' => 'Booking', 'customer' => 'Customer', 'departure' => 'Departure', 'leaves' => 'Leaves', 'status' => 'Status', 'total' => 'Total', 'paid' => 'Paid', 'balance' => 'Owed'],
            rows: $rows,
            filename: 'still-owed-'.now()->toDateString(),
        );
    }

    private function collectionsDocument(CarbonInterface $from, CarbonInterface $to): ListDocument
    {
        $collections = $this->collections($from, $to);
        $rows = array_map(fn (array $row): array => [
            'on' => $row['on']->format('Y-m-d'),
            'reference' => $row['reference'],
            'booking' => $row['booking'],
            'customer' => $row['customer'],
            'method' => $row['method'],
            'received' => $row['received'],
            'credited' => $row['credited']->format(),
            'verified_by' => $row['verified_by'],
        ], $collections['rows']);

        foreach ($collections['totals'] as $currency => $total) {
            $rows[] = ['on' => '', 'reference' => 'Total '.$currency, 'booking' => '', 'customer' => '', 'method' => '', 'received' => '', 'credited' => Money::ofMinor($total, $currency)->format(), 'verified_by' => ''];
        }

        return new ListDocument(
            title: 'What came in',
            subtitle: $from->format('j M Y').' to '.$to->format('j M Y'),
            columns: ['on' => 'Date', 'reference' => 'Payment', 'booking' => 'Booking', 'customer' => 'Customer', 'method' => 'How', 'received' => 'Received', 'credited' => 'Credited', 'verified_by' => 'Verified by'],
            rows: $rows,
            filename: 'came-in-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d'),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public static function subjectLabel(?Departure $departure): string
    {
        return $departure === null
            ? 'The company'
            : ($departure->package->title ?? 'Departure').' · '.$departure->date_start->format('j M Y');
    }

    /** @return Collection<int, Payment> */
    private function paymentsOn(Departure $departure): Collection
    {
        return Payment::query()->succeeded()
            ->where('payable_type', (new Booking)->getMorphClass())
            ->whereIn('payable_id', Booking::query()->where('departure_id', $departure->getKey())->select('id'))
            ->with(['payable.customer'])
            ->get();
    }

    /** @return Collection<int, DepartureCost> */
    private function paidCosts(?Departure $departure): Collection
    {
        return DepartureCost::query()
            ->where('status', DepartureCost::PAID)
            ->when($departure === null, fn ($query) => $query->whereNull('departure_id'), fn ($query) => $query->where('departure_id', $departure?->getKey()))
            ->get();
    }

    private static function travellersOn(Departure|int $departure): int
    {
        return (int) Booking::query()
            ->where('departure_id', $departure instanceof Departure ? $departure->getKey() : $departure)
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->sum('seats');
    }

    private static function paymentDate(Payment $payment): CarbonImmutable
    {
        return CarbonImmutable::instance($payment->paid_at ?? $payment->reviewed_at ?? $payment->created_at ?? now());
    }

    private static function costDate(DepartureCost $cost): CarbonImmutable
    {
        return CarbonImmutable::instance($cost->incurred_on ?? $cost->created_at ?? now());
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{totals: array<string, array{in: int, out: int}>, net: Money|null, unconvertible: list<string>}
     */
    private static function totals(array $lines): array
    {
        $totals = [];

        foreach ($lines as $line) {
            $totals[$line['currency']] ??= ['in' => 0, 'out' => 0];
            $totals[$line['currency']]['in'] += $line['in'];
            $totals[$line['currency']]['out'] += $line['out'];
        }

        ksort($totals);

        [$net, $unconvertible] = self::inBase(array_map(fn (array $total): int => $total['in'] - $total['out'], $totals));

        return ['totals' => $totals, 'net' => $net, 'unconvertible' => $unconvertible];
    }

    /**
     * One figure in the base currency, or null and the currencies that
     * stopped it — never a number with a made-up rate inside it.
     *
     * @param  array<string, int>  $perCurrency
     * @return array{Money|null, list<string>}
     */
    private static function inBase(array $perCurrency): array
    {
        $sum = 0;
        $unconvertible = [];

        foreach ($perCurrency as $currency => $minor) {
            try {
                $sum += Rates::toBase(Money::ofMinor($minor, $currency))->minor;
            } catch (InvalidArgumentException) {
                $unconvertible[] = $currency;
            }
        }

        return [$unconvertible === [] ? Money::ofMinor($sum, Rates::base()) : null, $unconvertible];
    }

    /** @param  array<string, int>  $perCurrency */
    private static function perCurrencyWords(array $perCurrency): string
    {
        if ($perCurrency === []) {
            return '';
        }

        ksort($perCurrency);

        return implode(' · ', array_map(fn (string $currency, int $minor): string => Money::ofMinor($minor, $currency)->format(), array_keys($perCurrency), $perCurrency));
    }
}
