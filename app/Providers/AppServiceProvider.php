<?php

namespace App\Providers;

use App\Models\Article;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GuideStep;
use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Stay;
use App\Models\Traveller;
use App\Models\Trip;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Observers\AuditObserver;
use App\Observers\CoverImageObserver;
use App\Observers\LostStayObserver;
use App\Support\InitialsAvatar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;
use Spatie\Translatable\Facades\Translatable;

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
        // The booking domain. Money and identity documents, so the trail
        // matters more here than anywhere else in the application — and the
        // observer masks passport and national-id values rather than
        // copying them into a table many more people can read.
        //
        // A booking's own status history lives in
        // `booking_status_transitions`, which records why as well as what.
        // This covers everything else: an amount corrected, a traveller's
        // details edited, a note changed.
        Booking::class,
        Customer::class,
        Traveller::class,

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
     * Models whose saves may bring a new image with them — §10.1.
     *
     * Hero banners generate their own variants in the upload controller;
     * they are here too so the one that arrives through any other path is
     * covered as well, and {@see CoverImageObserver} no-ops when the
     * variants already exist.
     *
     * @var list<class-string<Model>>
     */
    private const CARRY_A_COVER = [
        Trip::class,
        Package::class,
        Article::class,
        HeroBanner::class,
    ];

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineRateLimits();

        // `nonce="@cspNonce"` on an inline <script>. The middleware puts the
        // same value in the Content-Security-Policy header, and the browser
        // runs only the scripts carrying it.
        Blade::directive('cspNonce', static fn () => "<?php echo e(\App\Support\Csp::nonce()); ?>");

        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        // A stay that fell through becomes somebody to ring — §15.7. An
        // observer rather than a call in the booking service, because a
        // stay is lost by three different routes and the fourth one
        // somebody adds next year is the one that would quietly drop a
        // customer.
        Stay::observe(LostStayObserver::class);

        // Responsive variants at upload time — §10.1. Without this, every
        // cover uploaded after `images:responsive` was last run is
        // full-size again until somebody remembers to run it, which is
        // exactly the state that command existed to leave.
        foreach (self::CARRY_A_COVER as $model) {
            $model::observe(CoverImageObserver::class);
        }

        // A translated field falls back to English, and then — rather than
        // rendering nothing — to whatever language the record does have. A
        // card with a blank title is broken; a card with a title the reader
        // was not expecting is merely untranslated.
        Translatable::fallback(
            fallbackLocale: config('app.fallback_locale'),
            fallbackAny: true,
        );

        $this->drawPulseAvatarsLocally();
    }

    /**
     * Stop the Pulse dashboard telling gravatar.com who works here.
     *
     * Pulse's default user resolver builds an <img> pointing at
     * gravatar.com/avatar/<sha256 of the email address>. Opening the
     * dashboard would hand that hash — a stable identifier for the person
     * across every site using Gravatar — to a third party, once per staff
     * member listed, for as long as anyone leaves the page open.
     *
     * It was found because the picture was broken, not because anyone
     * thought to look: `img-src` allows 'self', data: and YouTube's
     * thumbnail hosts only, so the browser refused the request and the
     * Application Usage card showed a torn image next to each name. The same
     * default, with the same two problems, ships in Filament — see
     * App\Support\InitialsAvatar.
     *
     * `name` and `extra` keep Pulse's own defaults; only the avatar changes.
     */
    private function drawPulseAvatarsLocally(): void
    {
        Pulse::user(fn ($user): array => [
            'name' => $user->name ?? '',
            'extra' => $user->email ?? '',
            'avatar' => InitialsAvatar::forName($user->name ?? ''),
        ]);
    }

    /**
     * Rate limits for the endpoints that send mail or accept credentials.
     *
     * Login was already throttled by LoginRequest, and the email-verification
     * routes carry throttle middleware. Registration and password reset had
     * neither — and both send a message to whatever address the request names,
     * so an unauthenticated caller could use them to deliver mail to an
     * arbitrary inbox, as fast as the server would answer. Registration only
     * began sending mail when User took on the MustVerifyEmail contract, which
     * is what makes this worth closing now.
     *
     * Password reset is limited twice over: by caller, which stops one source
     * flooding many addresses, and by the address itself, which stops many
     * sources flooding one inbox. Neither limit alone covers the other case.
     */
    private function defineRateLimits(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perHour(5)->by($request->ip()),
            Limit::perHour(3)->by(Str::lower((string) $request->input('email'))),
        ]);

        // Guessing a reset token, or a password from inside a session.
        RateLimiter::for('credentials', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
