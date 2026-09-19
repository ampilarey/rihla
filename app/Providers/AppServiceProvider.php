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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
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
        $this->defineRateLimits();

        // `nonce="@cspNonce"` on an inline <script>. The middleware puts the
        // same value in the Content-Security-Policy header, and the browser
        // runs only the scripts carrying it.
        Blade::directive('cspNonce', static fn () => "<?php echo e(\App\Support\Csp::nonce()); ?>");

        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        // A translated field falls back to English, and then — rather than
        // rendering nothing — to whatever language the record does have. A
        // card with a blank title is broken; a card with a title the reader
        // was not expecting is merely untranslated.
        Translatable::fallback(
            fallbackLocale: config('app.fallback_locale'),
            fallbackAny: true,
        );
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
