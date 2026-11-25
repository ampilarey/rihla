<?php

namespace Modules\Travel;

use Illuminate\Support\ServiceProvider;

/**
 * Travel agency — flights, hotels abroad and visas for other countries
 * (docs/TRAVEL_AGENCY_PLAN.md). Enquire and quote; nothing is booked live
 * until a supplier account exists (A4).
 */
class TravelServiceProvider extends ServiceProvider
{
    public function boot(): void {}
}
