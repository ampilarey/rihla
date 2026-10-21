<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\CrmTask;
use App\Models\Departure;
use App\Models\DepartureChecklistItem;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Support\DepartureReadiness;
use Illuminate\Database\Eloquent\Collection;

/**
 * The morning message: what is waiting for this person, by what they may
 * do, then the departures ahead. The same questions the Today page and
 * the departure board answer, in a shape that fits a phone notification.
 */
class DailySummary
{
    public function for(User $user): string
    {
        $lines = [
            '<b>'.e(now()->translatedFormat('l j F')).'</b>',
            'Good morning, '.e($user->name).'.',
            '',
        ];

        $queue = [];

        if ($user->can('task.viewAny')) {
            $n = CrmTask::query()->dueBy(now()->endOfDay())->where('owner_id', $user->getKey())->count();
            $queue[] = $n > 0 ? self::count($n, 'task').' of yours due today or overdue' : null;
        }

        if ($user->can('payment.reconcile')) {
            $n = Payment::query()->awaitingReview()->count();
            $queue[] = $n > 0 ? self::count($n, 'payment').' to verify (/slips)' : null;
        }

        if ($user->can('booking.viewAny')) {
            $n = PaymentSchedule::query()
                ->whereDate('due_on', '<', today())
                ->whereHas('booking', fn ($booking) => $booking->whereIn('status', [Booking::HELD, Booking::CONFIRMED]))
                ->with('booking')
                ->get()
                ->filter(fn (PaymentSchedule $instalment): bool => $instalment->isOverdue())
                ->count();
            $queue[] = $n > 0 ? self::count($n, 'instalment').' fallen behind' : null;
        }

        if ($user->can('document.update')) {
            $n = Document::query()->pending()->count();
            $queue[] = $n > 0 ? self::count($n, 'document copy', 'document copies').' to verify' : null;

            $n = DocumentExtraction::query()->where('status', DocumentExtraction::PROPOSED)->whereNotNull('fields')
                ->whereHas('version', fn ($version) => $version->whereNull('superseded_at'))
                ->count();
            $queue[] = $n > 0 ? self::count($n, 'scan reading').' to accept' : null;
        }

        if ($user->can('checklist.viewAny')) {
            $n = DepartureChecklistItem::query()
                ->open()
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', today())
                ->whereHas('departure', fn ($departure) => $departure->where('date_end', '>=', today()))
                ->count();
            $queue[] = $n > 0 ? self::count($n, 'checklist item').' overdue (/checklist)' : null;
        }

        if ($user->can('departure.board')) {
            $n = self::upcoming()->filter(fn (Departure $departure): bool => DepartureReadiness::hasBlockers($departure))->count();
            $queue[] = $n > 0 ? self::count($n, 'departure').' with something blocking (/ready)' : null;
        }

        $queue = array_values(array_filter($queue));

        if ($queue === []) {
            $lines[] = 'Nothing is waiting for you.';
        } else {
            $lines[] = '<b>Waiting for you</b>';

            foreach ($queue as $item) {
                $lines[] = '• '.e($item);
            }
        }

        if (Router::seesDepartures($user)) {
            $departures = self::upcoming()->take(3);

            if ($departures->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '<b>Departures ahead</b>';

                foreach ($departures as $departure) {
                    $lines[] = '• '.e(self::label($departure)).' · '.($departure->capacity_total - $departure->seats_remaining).'/'.$departure->capacity_total.' seats';
                }
            }
        }

        $lines[] = '';
        $lines[] = 'Panel: '.e(Notifier::panelUrl());

        return implode("\n", $lines);
    }

    /** "#12 · Shawwal Umrah 2027 · 20 Dec" */
    public static function label(Departure $departure): string
    {
        return '#'.$departure->getKey().' · '.($departure->package->title ?? 'Departure').' · '.$departure->date_start->format('j M');
    }

    /** @return Collection<int, Departure> */
    public static function upcoming()
    {
        return Departure::query()
            ->where('date_end', '>=', today())
            ->with('package')
            ->orderBy('date_start')
            ->get();
    }

    private static function count(int $n, string $singular, ?string $plural = null): string
    {
        return $n.' '.($n === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
