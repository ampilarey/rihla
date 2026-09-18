<?php

namespace App\Providers;

use App\Models\GuideStep;
use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Observers\AuditObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Models whose every change is recorded.
     *
     * Deliberately explicit rather than "everything": AuditLog itself must
     * never be audited, or one edit would write a row describing the row it
     * just wrote. Bookings, payments and documents join this list when they
     * exist — the mechanism is here first so they cannot arrive without it.
     *
     * @var list<class-string<Model>>
     */
    private const AUDITED = [
        Trip::class,
        Media::class,
        GuideStep::class,
        HeroBanner::class,
        WhySection::class,
        WhyFeature::class,
        Setting::class,
        User::class,
    ];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

    }
}
