<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Books as BooksPage;
use App\Filament\Pages\DepartureBoard;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\Books;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The figures at the top of the home page (companies plan, Phase 3g, from
 * Hijaz). Each card shows only to somebody who may see what it counts, so a
 * desk that takes bookings never sees the money, and a reader of the books
 * never sees passports. Every figure comes from a service that already
 * computes it for another screen; nothing here works money out afresh.
 */
class DashboardStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    /** Two cards a row on a phone, three from a tablet up. */
    protected int|array|null $columns = ['default' => 2, 'md' => 3];

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && self::cards($user) !== [];
    }

    /** @return list<string> */
    private static function cards(User $user): array
    {
        $cards = [];
        $seesDepartures = $user->can('departure.viewAny') || $user->can('departure.board');

        if ($seesDepartures) {
            $cards[] = 'next';
            $cards[] = 'seats';
        }

        if ($user->can('booking.viewAny')) {
            $cards[] = 'travellers';
        }

        if ($user->can('books.view')) {
            $cards[] = 'received';
        }

        if ($user->can('booking.viewAny') && $user->can('payment.viewAny')) {
            $cards[] = 'owed';
        }

        if ($user->can('payment.reconcile')) {
            $cards[] = 'toCheck';
        }

        return $cards;
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return array_map(fn (string $card): Stat => match ($card) {
            'next' => $this->nextDeparture(),
            'seats' => $this->seats(),
            'travellers' => $this->travellers(),
            'received' => $this->received(),
            'owed' => $this->owed(),
            default => $this->toCheck(),
        }, self::cards($user));
    }

    private function nextDeparture(): Stat
    {
        $departure = Departure::query()->open()->upcoming()->with('package')->orderBy('date_start')->first();

        if ($departure === null || $departure->date_start === null) {
            return Stat::make('Next departure', 'None ahead')->description('Nothing open with a date')->color('gray');
        }

        $days = (int) today()->diffInDays($departure->date_start);

        return Stat::make('Next departure', match ($days) {
            0 => 'Today',
            1 => 'Tomorrow',
            default => 'In '.$days.' days',
        })
            ->description(($departure->package->title ?? 'Departure').' · '.$departure->date_start->format('j M'))
            ->descriptionIcon('heroicon-m-paper-airplane')
            ->color($days <= 14 ? 'warning' : 'primary')
            ->url(DepartureBoard::getUrl());
    }

    private function seats(): Stat
    {
        $departures = Departure::query()->open()->aheadOrUndated()->get();
        $taken = (int) $departures->sum(fn (Departure $d): int => $d->seats_taken);
        $total = (int) $departures->sum('capacity_total');
        $share = $total > 0 ? (int) round($taken * 100 / $total) : 0;

        return Stat::make('Seats filled', $taken.' of '.$total)
            ->description($share.'% across '.$departures->count().' open '.str('departure')->plural($departures->count()))
            ->descriptionIcon('heroicon-m-user-group')
            ->color($share >= 90 ? 'success' : 'primary')
            ->url(DepartureBoard::getUrl());
    }

    private function travellers(): Stat
    {
        $ahead = Departure::query()->aheadOrUndated()->select('id');
        $confirmed = BookingTraveller::query()
            ->whereIn('booking_id', Booking::query()->where('status', Booking::CONFIRMED)->whereIn('departure_id', $ahead)->select('id'))
            ->count();
        $holding = Booking::query()->where('status', Booking::HELD)->whereIn('departure_id', Departure::query()->aheadOrUndated()->select('id'))->count();

        return Stat::make('Travellers confirmed', (string) $confirmed)
            ->description($holding === 0 ? 'No booking still holding seats' : $holding.' '.str('booking')->plural($holding).' still holding seats')
            ->descriptionIcon('heroicon-m-ticket')
            ->url(BookingResource::getUrl());
    }

    /** From the books' own monthly summary, per currency: nothing is converted here. */
    private function received(): Stat
    {
        [$now, $before] = app(Books::class)->monthly(2);

        return Stat::make('Received this month', self::words($now['in']))
            ->description('Last month '.self::words($before['in']))
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('success')
            ->url(BooksPage::getUrl());
    }

    private function owed(): Stat
    {
        $outstanding = app(Books::class)->outstanding();
        $count = count($outstanding['rows']);

        return Stat::make('Still owed', self::words($outstanding['totals']))
            ->description($count === 0 ? 'Every booking is paid up' : 'On '.$count.' '.str('booking')->plural($count))
            ->descriptionIcon('heroicon-m-clock')
            ->color($count === 0 ? 'success' : 'warning')
            ->url(BookingResource::getUrl());
    }

    private function toCheck(): Stat
    {
        $waiting = Payment::query()->whereIn('status', [Payment::PENDING, Payment::AWAITING_REVIEW])->where('amount_minor', '>', 0)->count();

        return Stat::make('Payments to check', (string) $waiting)
            ->description($waiting === 0 ? 'Nothing waiting for Finance' : 'Recorded, not yet confirmed in the account')
            ->descriptionIcon('heroicon-m-check-badge')
            ->color($waiting === 0 ? 'success' : 'danger')
            ->url(PaymentResource::getUrl());
    }

    /** @param  array<string, int>  $perCurrency */
    private static function words(array $perCurrency): string
    {
        $parts = array_map(fn (string $currency, int $minor): string => Money::ofMinor($minor, $currency)->format(), array_keys($perCurrency), $perCurrency);

        return $parts === [] ? 'Nothing' : implode(' + ', $parts);
    }
}
