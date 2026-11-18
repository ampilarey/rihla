<?php

namespace App\Providers\Filament;

use App\Filament\BottomNav;
use App\Filament\Company\Pages\Dashboard;
use App\Filament\InitialsAvatarProvider;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\UseTenantCompany;
use App\Models\Partner;
use App\Support\Brand;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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

/**
 * Each Umrah company's own panel, at /company/{company} — companies plan,
 * Phase 2 (decision 16).
 *
 * The tenant is the company's `partners` row, reached by its slug, and a
 * person gets in only through an accepted `company_memberships` row. Once
 * in, {@see UseTenantCompany} points `CompanyContext` at the tenant, so the
 * Phase 1 scope and gate wall every query to that company; Filament's own
 * tenant scoping, through each model's `company()` relation, is a third.
 *
 * At a path on Rihla's own address for now. The company's own address
 * (`company.rihla.mv/admin`) needs the wildcard subdomain in cPanel and
 * arrives with company websites in Phase 4.
 */
class CompanyPanelProvider extends PanelProvider
{
    /** The one place the panel's path is written. */
    public const PATH = 'company';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('company')
            ->path(self::PATH)
            ->brandName(fn (): string => filament()->getTenant()?->getAttribute('name') ?? 'Your company')
            ->login()
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Brand::WINE_SCALE,
                'gray' => Color::Stone,
            ])
            ->tenant(Partner::class, slugAttribute: 'slug', ownershipRelationship: 'company')
            ->tenantMenu(fn (): bool => (auth()->user()?->companies()->count() ?? 0) > 1)
            ->tenantMiddleware([UseTenantCompany::class], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Company/Resources'), for: 'App\Filament\Company\Resources')
            ->discoverPages(in: app_path('Filament/Company/Pages'), for: 'App\Filament\Company\Pages')
            ->pages([Dashboard::class])
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
