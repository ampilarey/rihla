<?php

namespace App\Providers\Filament;

use App\Filament\Host\Pages\Dashboard;
use App\Filament\Host\Pages\RegisterHost;
use App\Filament\InitialsAvatarProvider;
use App\Http\Middleware\RequireSecondFactor;
use App\Models\Partner;
use App\Support\Brand;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
 * The host panel, at /host — §16.6, ADR 0008 decision 6.
 *
 * A second Filament panel for the people who run a guesthouse, scoped by
 * tenant: every screen is one host's, and a host's team can only ever
 * query their own rows. The same colours and middleware as the staff
 * panel, so the two look like one product.
 *
 * Two departures from the staff panel, both deliberate:
 *
 * - **Its own `->login()`.** The staff panel has none on purpose — a staff
 *   member signs in at /login. A host is not staff and is not sent there.
 * - **Registration**, as a custom page that answers 404 until the owner
 *   opens it (`marketplace.host_registration.enabled`, §16.12). This is not
 *   the Breeze /register that D16 closed; that stays closed.
 */
class HostPanelProvider extends PanelProvider
{
    /** The one place the panel's path is written; `SecurityHeaders` reads it. */
    public const PATH = 'host';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('host')
            ->path(self::PATH)
            ->brandName('Rihla for Hosts')
            ->login()
            ->registration(RegisterHost::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Brand::WINE_SCALE,
                'gray' => Color::Stone,
            ])
            ->tenant(Partner::class, slugAttribute: 'slug')
            ->tenantMenu(fn (): bool => (auth()->user()?->hosts()->count() ?? 0) > 1)
            ->discoverResources(in: app_path('Filament/Host/Resources'), for: 'App\Filament\Host\Resources')
            ->discoverPages(in: app_path('Filament/Host/Pages'), for: 'App\Filament\Host\Pages')
            ->pages([Dashboard::class])
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
                RequireSecondFactor::class,
            ]);
    }
}
