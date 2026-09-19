<?php

namespace App\Providers\Filament;

use App\Filament\InitialsAvatarProvider;
use App\Support\Brand;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff panel, at /staff.
 *
 * Deliberately *not* at /admin. The hand-rolled Blade admin lives there and
 * still owns trips, media, the guide, hero banners, the why-section and
 * settings. New modules are built here instead of being added to the Blade
 * panel, and the Blade screens move across in Phase 3 rather than in a
 * big-bang rewrite. See docs/adr/0003-filament-for-new-admin-modules.md.
 *
 * Three departures from what `filament:install` generates:
 *
 * - No `->login()`. The application already has one login form; a second one
 *   at /staff/login would be a second place to get session handling, rate
 *   limiting and password resets wrong. A guest is sent to the existing
 *   /login, and the panel middleware brings them back.
 * - Brand colours rather than Filament's amber default, so the two panels do
 *   not look like two different products.
 * - Panel access is a permission, not merely being signed in — see
 *   `User::canAccessPanel()`. Every customer account that Phase 3 introduces
 *   will be an authenticated user, and none of them may see this.
 */
class StaffPanelProvider extends PanelProvider
{
    /**
     * The one place the panel's path is written.
     *
     * `SecurityHeaders` needs it too — the panel cannot run under the site's
     * nonce-based script policy, so the middleware relaxes that one directive
     * for these URLs and no others.
     */
    public const PATH = 'staff';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('staff')
            ->path(self::PATH)
            ->brandName('Rihla Staff')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::hex(Brand::WINE),
                'gray' => Color::Stone,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
