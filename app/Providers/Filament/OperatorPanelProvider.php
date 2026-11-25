<?php

namespace App\Providers\Filament;

use App\Filament\BottomNav;
use App\Filament\InitialsAvatarProvider;
use App\Http\Middleware\RequireSecondFactor;
use App\Models\Partner;
use App\Support\Brand;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Transport\Filament\Pages\BoatScan;
use Modules\Transport\Filament\Resources\BoatBookings\BoatBookingResource;
use Modules\Transport\Filament\Resources\BoatRoutes\BoatRouteResource;
use Modules\Transport\Filament\Resources\OperatorStatements\OperatorStatementResource;
use Modules\Transport\Filament\Resources\Sailings\SailingResource;
use Modules\Transport\Filament\Resources\Vessels\VesselResource;

/**
 * A boat operator's own panel, at /operator — docs/TRANSPORT_PLAN.md (T5).
 *
 * It serves the staff panel's transport screens, scoped to the tenant by
 * `OperatorContext`, so an operator runs their boats, routes, timetable,
 * bookings, boarding and statements on the same screens Rihla's staff use,
 * and sees nobody else's. Permissions inside it come from the membership
 * (`OperatorMembership::ABILITIES`), never from a Rihla role.
 */
class OperatorPanelProvider extends PanelProvider
{
    /** The one place the panel's path is written; `SecurityHeaders` reads it. */
    public const PATH = 'operator';

    /** @var list<class-string> */
    public const RESOURCES = [
        SailingResource::class,
        BoatBookingResource::class,
        BoatRouteResource::class,
        VesselResource::class,
        OperatorStatementResource::class,
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path(self::PATH)
            ->brandName('Rihla for Boat Operators')
            ->login()
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Brand::WINE_SCALE,
                'gray' => Color::Stone,
            ])
            ->tenant(Partner::class, slugAttribute: 'slug')
            ->tenantMenu(fn (): bool => (auth()->user()?->operators()->count() ?? 0) > 1)
            ->resources(self::RESOURCES)
            ->pages([Dashboard::class, BoatScan::class])
            // The things a crew member does most, along the bottom of a phone.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => BottomNav::render())
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
            ], isPersistent: true);
    }
}
