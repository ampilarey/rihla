<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Departure;
use App\Models\GuideStep;
use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Package;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Policies\AuditLogPolicy;
use App\Policies\DeparturePolicy;
use App\Policies\GuideStepPolicy;
use App\Policies\HeroBannerPolicy;
use App\Policies\MediaPolicy;
use App\Policies\PackagePolicy;
use App\Policies\PersonPolicy;
use App\Policies\SettingPolicy;
use App\Policies\TripPolicy;
use App\Policies\UserPolicy;
use App\Policies\WhyFeaturePolicy;
use App\Policies\WhySectionPolicy;
use App\Support\Access;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Trip::class => TripPolicy::class,
        Package::class => PackagePolicy::class,
        Departure::class => DeparturePolicy::class,
        Person::class => PersonPolicy::class,
        Media::class => MediaPolicy::class,
        GuideStep::class => GuideStepPolicy::class,
        HeroBanner::class => HeroBannerPolicy::class,
        WhySection::class => WhySectionPolicy::class,
        WhyFeature::class => WhyFeaturePolicy::class,
        Setting::class => SettingPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        User::class => UserPolicy::class,
    ];

    public function boot(): void
    {
        // Super Admin is granted everything here rather than by holding every
        // permission. A permission added later is then covered automatically,
        // instead of silently locking out the one role that must never be
        // locked out. Returning null — not false — lets every other check fall
        // through to the normal policy and permission chain.
        Gate::before(function ($user, string $ability) {
            return $user->hasRole(Access::SUPER_ADMIN) ? true : null;
        });

        // `admin` used to mean `is_admin === true`. It now means "may open the
        // admin panel at all", which every staff role holds. It stays a gate
        // because the whole admin route group is behind `can:admin`, and that
        // coarse check is the first line of defence in front of the
        // per-resource policies.
        Gate::define('admin', fn ($user) => $user->can('admin.access'));

        // Kept for the views and code that already ask this question.
        Gate::define('manage-content', fn ($user) => $user->can('trip.update')
            || $user->can('media.update')
            || $user->can('guide.update'));

        // Pulse names its own gate, and defines a default of "only in the
        // local environment" from a callAfterResolving hook. That default is
        // the right way round — it denies on production rather than allowing —
        // but it means this definition has to land *after* Pulse's, and the
        // Gate::before call at the top of this method is what resolves the
        // Gate and fires Pulse's hook. Written here, next to the other gates,
        // so the ordering is visible rather than spread across providers.
        //
        // Tested rather than assumed: a gate registered where it cannot take
        // effect looks exactly like one that works. See PulseDashboardTest.
        Gate::define('viewPulse', fn ($user) => $user->can('pulse.view'));
    }
}
