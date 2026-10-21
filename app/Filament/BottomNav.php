<?php

namespace App\Filament;

use App\Filament\Host\Pages\Calendar;
use App\Filament\Host\Pages\Dashboard as HostDashboard;
use App\Filament\Host\Pages\Housekeeping;
use App\Filament\Host\Pages\Messages;
use App\Filament\Host\Resources\Bookings\BookingResource as HostBookingResource;
use App\Filament\Pages\DepartureBoard;
use App\Filament\Pages\Today;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Support\HostContext;
use Filament\Facades\Filament;
use Throwable;

/**
 * The bar along the bottom of a phone screen: the five things somebody
 * does most, one tap away without opening the menu.
 *
 * Each item is shown only to somebody who may open it, so Finance's bar
 * carries Payments and a Content Manager's carries nothing at all — a bar
 * of one or two items is not worth the space it takes. Never more than
 * five. The sidebar takes over from 64rem, where the bar is hidden.
 */
final class BottomNav
{
    public const MOST = 5;

    public const LEAST = 3;

    /**
     * @return list<array{label: string, url: string, icon: string, active: bool}>
     */
    public static function items(): array
    {
        if (! Filament::auth()->check()) {
            return [];
        }

        $items = [];

        foreach (self::candidates() as [$label, $page, $icon]) {
            try {
                if (! $page::canAccess()) {
                    continue;
                }

                $url = $page::getUrl();
            } catch (Throwable) {
                // A page that cannot say — a host page with no tenant, a
                // resource whose policy throws — is left off rather than
                // taking the whole bar down with it.
                continue;
            }

            $items[] = ['label' => $label, 'url' => $url, 'icon' => $icon, 'active' => false];

            if (count($items) === self::MOST) {
                break;
            }
        }

        if (count($items) < self::LEAST) {
            return [];
        }

        return self::markCurrent($items);
    }

    /**
     * The current page's item, and only that one. The dashboard's URL is a
     * prefix of every other page in its panel, so a plain prefix match lit
     * "Today" on the calendar; the longest URL that matches is the page.
     *
     * @param  list<array{label: string, url: string, icon: string, active: bool}>  $items
     * @return list<array{label: string, url: string, icon: string, active: bool}>
     */
    private static function markCurrent(array $items): array
    {
        $current = request()->url();
        $best = null;

        foreach ($items as $index => $item) {
            $matches = $current === $item['url'] || str_starts_with($current, $item['url'].'/');

            if ($matches && ($best === null || strlen($item['url']) > strlen($items[$best]['url']))) {
                $best = $index;
            }
        }

        if ($best !== null) {
            $items[$best]['active'] = true;
        }

        return $items;
    }

    /**
     * In the order they are offered. The first five somebody may open are
     * their bar.
     *
     * @return list<array{0: string, 1: class-string, 2: string}>
     */
    private static function candidates(): array
    {
        if (Filament::getCurrentPanel()?->getId() === 'host') {
            if (HostContext::current() === null) {
                return [];
            }

            return [
                ['Today', HostDashboard::class, 'home'],
                ['Bookings', HostBookingResource::class, 'bookings'],
                ['Calendar', Calendar::class, 'calendar'],
                ['Rooms', Housekeeping::class, 'rooms'],
                ['Messages', Messages::class, 'messages'],
            ];
        }

        return [
            ['Today', Today::class, 'home'],
            ['Bookings', BookingResource::class, 'bookings'],
            ['Payments', PaymentResource::class, 'payments'],
            ['Departures', DepartureBoard::class, 'trips'],
            ['Documents', DocumentResource::class, 'documents'],
            ['Customers', CustomerResource::class, 'people'],
        ];
    }

    public static function render(): string
    {
        $items = self::items();

        return $items === [] ? '' : view('filament.bottom-nav', ['items' => $items])->render();
    }
}
