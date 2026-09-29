<?php

namespace App\Filament\Host\Widgets;

use App\Filament\Host\Pages\Housekeeping;
use App\Filament\Host\Pages\Messages;
use App\Filament\Host\Resources\Bookings\BookingResource;
use App\Models\Partner;
use App\Models\PropertyUnit;
use App\Models\Stay;
use App\Services\Stays\StayBill;
use App\Support\HostContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Today at the desk — §16.6. Each number is a link to the list behind it.
 */
class Today extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Today';

    public static function canView(): bool
    {
        return HostContext::current() !== null;
    }

    protected function getStats(): array
    {
        $host = HostContext::current() ?? abort(404);

        $inHouse = $this->stays($host)->where('stays.status', Stay::CHECKED_IN)->with('charges')->get();
        $owing = $inHouse->filter(fn (Stay $stay): bool => StayBill::for($stay)->balance()->minor > 0)->count();

        return [
            Stat::make('Waiting for your answer', $this->stays($host)->where('stays.status', Stay::REQUESTED)->count())
                ->description('Requests from guests')
                ->url($this->bookings(['status' => ['values' => [Stay::REQUESTED]]])),
            Stat::make('Arriving today', BookingResource::today($this->stays($host), 'arriving')->count())
                ->url($this->bookings(['today' => ['value' => 'arriving']])),
            Stat::make('Leaving today', BookingResource::today($this->stays($host), 'leaving')->count())
                ->url($this->bookings(['today' => ['value' => 'leaving']])),
            Stat::make('In house', $inHouse->count())
                ->url($this->bookings(['today' => ['value' => 'in_house']])),
            Stat::make('Still owing', $owing)
                ->description('Guests in house with a balance')
                ->url($this->bookings(['today' => ['value' => 'in_house']])),
            Stat::make('Unread messages', Messages::unreadFor($host->getKey()))
                ->url(Messages::getUrl()),
            Stat::make('Rooms to clean', Housekeeping::unitsOf($host->getKey())->where('housekeeping', PropertyUnit::DIRTY)->count())
                ->url(Housekeeping::getUrl(['filters' => ['housekeeping' => ['value' => PropertyUnit::DIRTY]]])),
        ];
    }

    /** @return Builder<Stay> */
    private function stays(Partner $host): Builder
    {
        return Stay::query()->whereHas('property', fn (Builder $query) => $query->where('partner_id', $host->getKey()));
    }

    /** @param array<string, mixed> $filters */
    private function bookings(array $filters): string
    {
        return BookingResource::getUrl('index', ['filters' => $filters]);
    }
}
