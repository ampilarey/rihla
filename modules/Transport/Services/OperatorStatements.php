<?php

namespace Modules\Transport\Services;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\OperatorStatement;
use Modules\Umrah\Services\Invoices\Documents;
use Modules\Umrah\Services\Operations\ListDocument;

/**
 * A boat operator's monthly statement — docs/TRANSPORT_PLAN.md (T5).
 *
 * A month is the sailings that *left* in it, so a seat booked in March for
 * April belongs to April's statement. Only confirmed bookings count, and
 * only the money that actually arrived (`paid_minor`): a seat sold online
 * was paid to Rihla, which keeps the commission frozen on the booking and
 * owes the operator the rest. Seats the operator sold at their own desk
 * are counted for the record and owe nothing either way.
 */
final class OperatorStatements
{
    public function __construct(private readonly Documents $documents) {}

    /**
     * @return Collection<int, BoatBooking>
     */
    public function bookings(Partner $operator, CarbonImmutable $month): Collection
    {
        return BoatBooking::query()
            ->where('status', BoatBooking::CONFIRMED)
            ->whereHas('sailing', fn ($q) => $q
                ->whereBetween('departs_at', [$month->startOfMonth(), $month->endOfMonth()])
                ->whereHas('route', fn ($r) => $r->where('partner_id', $operator->getKey())))
            ->with(['sailing.route.origin', 'sailing.route.destination'])
            ->orderBy('id')
            ->get();
    }

    public static function commissionOf(BoatBooking $booking): int
    {
        return $booking->commission_pct === null ? 0 : (int) round($booking->paid_minor * $booking->commission_pct / 100);
    }

    /**
     * Issue the month's statements, one per currency. A month is issued
     * once and only after it is over: a statement that changed after the
     * operator read it would be worse than none.
     *
     * @return list<OperatorStatement>
     */
    public function issue(Partner $operator, CarbonImmutable $month, ?User $by): array
    {
        $month = $month->startOfMonth();

        if ($operator->kind !== Partner::KIND_OPERATOR) {
            throw new DeskRefusal('Statements are for outside boat operators; Rihla does not invoice itself.');
        }

        if ($month->endOfMonth()->isFuture()) {
            throw new DeskRefusal($month->format('F Y').' is not over yet.');
        }

        if (OperatorStatement::query()->where('partner_id', $operator->getKey())->whereDate('period_start', $month)->exists()) {
            throw new DeskRefusal($operator->name.'\'s statement for '.$month->format('F Y').' has already been issued.');
        }

        $bookings = $this->bookings($operator, $month);

        if ($bookings->isEmpty()) {
            throw new DeskRefusal($operator->name.' had no confirmed seats on sailings in '.$month->format('F Y').'.');
        }

        return DB::transaction(function () use ($operator, $month, $by, $bookings): array {
            $issued = [];

            foreach ($bookings->groupBy('currency') as $currency => $inCurrency) {
                $online = $inCurrency->filter(fn (BoatBooking $b): bool => $b->commission_pct !== null);
                $desk = $inCurrency->filter(fn (BoatBooking $b): bool => $b->commission_pct === null);
                $paid = (int) $online->sum('paid_minor');
                $commission = (int) $online->sum(fn (BoatBooking $b): int => self::commissionOf($b));

                $statement = new OperatorStatement;
                $statement->forceFill([
                    'reference' => sprintf('RIH-OS-%s-%d-%s', $month->format('Ym'), $operator->getKey(), $currency),
                    'partner_id' => $operator->getKey(),
                    'period_start' => $month->toDateString(),
                    'period_end' => $month->endOfMonth()->toDateString(),
                    'currency' => (string) $currency,
                    'online_count' => $online->count(),
                    'online_paid_minor' => $paid,
                    'commission_minor' => $commission,
                    'payable_minor' => $paid - $commission,
                    'desk_count' => $desk->count(),
                    'desk_total_minor' => (int) $desk->sum('total_minor'),
                    'issued_at' => now(),
                    'issued_by' => $by?->getKey(),
                ])->save();

                $issued[] = $statement;
            }

            return $issued;
        });
    }

    /** The statement and every seat behind it, as a PDF. */
    public function pdf(OperatorStatement $statement): PdfDocument
    {
        /** @var Partner $operator */
        $operator = $statement->operator()->firstOrFail();
        $month = CarbonImmutable::parse($statement->period_start);
        $rows = [];

        foreach ($this->bookings($operator, $month)->where('currency', $statement->currency)->values() as $i => $booking) {
            $online = $booking->commission_pct !== null;
            $rows[] = [
                'n' => $i + 1,
                'booking' => $booking->reference,
                'sailing' => $booking->sailing->route->label().', '.$booking->sailing->departs_at->format('j M H:i'),
                'seats' => $booking->seats,
                'sold' => $online ? 'Online' : 'Your desk',
                'paid' => $online ? $statement->money($booking->paid_minor)->format() : '—',
                'commission' => $online ? $booking->commission_pct.'% · '.$statement->money(self::commissionOf($booking))->format() : '—',
            ];
        }

        $list = new ListDocument(
            title: 'Statement '.$statement->reference,
            subtitle: $operator->name.' · sailings in '.$month->format('F Y'),
            columns: ['n' => '#', 'booking' => 'Booking', 'sailing' => 'Sailing', 'seats' => 'Seats', 'sold' => 'Sold', 'paid' => 'Paid to Rihla', 'commission' => 'Commission'],
            rows: $rows,
            landscape: true,
            notes: [
                'Seats sold online: '.$statement->online_count.', paid to Rihla '.$statement->money($statement->online_paid_minor)->format()
                    .'. Rihla\'s commission '.$statement->money($statement->commission_minor)->format()
                    .'. Payable to you: '.$statement->money($statement->payable_minor)->format().'.',
                'Seats you sold yourself: '.$statement->desk_count.', worth '.$statement->money($statement->desk_total_minor)->format().'. That money is yours and is not part of this statement.',
                'Only confirmed bookings on sailings that left in the month count, and only money that arrived.',
            ],
            filename: $statement->reference,
        );

        return Pdf::loadView('pdf.list', ['list' => $list, 'issuer' => $this->documents->issuer()])->setPaper('a4', 'landscape');
    }
}
