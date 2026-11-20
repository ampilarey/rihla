<?php

namespace App\Providers\Filament;

use App\Filament\BottomNav;
use App\Filament\InitialsAvatarProvider;
use App\Filament\Pages\Books;
use App\Filament\Pages\Performance;
use App\Filament\Pages\Today;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\LedgerCategories\LedgerCategoryResource;
use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use App\Filament\Resources\Notices\NoticeResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\MoneyChart;
use App\Filament\Widgets\SeatsChart;
use App\Http\Middleware\RecordSupportVisit;
use App\Http\Middleware\RequireSecondFactor;
use App\Http\Middleware\UseTenantCompany;
use App\Models\Partner;
use App\Support\Brand;
use App\Support\CompanyRequest;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Companies\Filament\OverdueInvoiceBanner;
use Modules\Companies\Filament\Pages\Auth\Login;
use Modules\Companies\Filament\Pages\Dashboard;
use Modules\Content\Filament\Resources\People\PersonResource;
use Modules\Telegram\Filament\Pages\Telegram;
use Modules\Umrah\Filament\Pages\DepartureBoard;
use Modules\Umrah\Filament\Pages\Forecast;
use Modules\Umrah\Filament\Pages\Lists;
use Modules\Umrah\Filament\Pages\NusukGate;
use Modules\Umrah\Filament\Pages\Profitability;
use Modules\Umrah\Filament\Pages\Scan;
use Modules\Umrah\Filament\Pages\VisaBatch;
use Modules\Umrah\Filament\Pages\WhoSendsUsPeople;
use Modules\Umrah\Filament\Resources\Announcements\AnnouncementResource;
use Modules\Umrah\Filament\Resources\Bookings\BookingResource;
use Modules\Umrah\Filament\Resources\Broadcasts\EmergencyBroadcastResource;
use Modules\Umrah\Filament\Resources\BusGroups\BusGroupResource;
use Modules\Umrah\Filament\Resources\Checklists\DepartureChecklistItemResource;
use Modules\Umrah\Filament\Resources\Costs\DepartureCostResource;
use Modules\Umrah\Filament\Resources\Documents\DocumentResource;
use Modules\Umrah\Filament\Resources\Enquiries\EnquiryResource;
use Modules\Umrah\Filament\Resources\ExcursionBookings\ExcursionBookingResource;
use Modules\Umrah\Filament\Resources\Excursions\ExcursionResource;
use Modules\Umrah\Filament\Resources\FlightGroups\FlightGroupResource;
use Modules\Umrah\Filament\Resources\Flights\DepartureFlightResource;
use Modules\Umrah\Filament\Resources\Incidents\IncidentResource;
use Modules\Umrah\Filament\Resources\OperationsLog\OperationsLogEntryResource;
use Modules\Umrah\Filament\Resources\Packages\PackageResource;
use Modules\Umrah\Filament\Resources\Permits\NusukPermitResource;
use Modules\Umrah\Filament\Resources\Quotations\QuotationResource;
use Modules\Umrah\Filament\Resources\RollCalls\RollCallResource;
use Modules\Umrah\Filament\Resources\Rooms\RoomResource;
use Modules\Umrah\Filament\Resources\Tasks\CrmTaskResource;
use Modules\Umrah\Filament\Resources\Transfers\DepartureTransferResource;
use Modules\Umrah\Filament\Resources\Visas\VisaApplicationResource;
use Modules\Umrah\Filament\Resources\Waitlist\WaitlistEntryResource;

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

    /**
     * Rihla's own Umrah screens, served here for the company — Phase 2b.
     *
     * The same classes as `/staff`: the company scope narrows every query
     * to the tenant and the company gate answers every permission from the
     * person's role there, so nothing is copied and the two never drift.
     * Listed rather than discovered, because what is *not* here matters as
     * much: Rihla's website content, the guesthouse marketplace, its own
     * staff accounts, settings and audit log are never a company's.
     *
     * @var list<class-string<resource>>
     */
    public const RESOURCES = [
        BookingResource::class,
        CustomerResource::class,
        PackageResource::class,
        PaymentResource::class,
        DocumentResource::class,
        VisaApplicationResource::class,
        NusukPermitResource::class,
        EnquiryResource::class,
        QuotationResource::class,
        CrmTaskResource::class,
        WaitlistEntryResource::class,
        NoticeResource::class,
        DepartureCostResource::class,
        ExchangeRateResource::class,
        RoomResource::class,
        BusGroupResource::class,
        FlightGroupResource::class,
        ExcursionResource::class,
        ExcursionBookingResource::class,
        AccountResource::class,
        LedgerEntryResource::class,
        LedgerCategoryResource::class,
        DepartureFlightResource::class,
        DepartureTransferResource::class,
        DepartureChecklistItemResource::class,
        RollCallResource::class,
        IncidentResource::class,
        OperationsLogEntryResource::class,
        AnnouncementResource::class,
        EmergencyBroadcastResource::class,
        PersonResource::class,
    ];

    /**
     * Rihla's Umrah pages, served here too. Not the AI drafting page (it
     * spends Rihla's API key), and nothing behind `admin.access`.
     *
     * @var list<class-string<Page>>
     */
    public const PAGES = [
        Today::class,
        DepartureBoard::class,
        Lists::class,
        Scan::class,
        VisaBatch::class,
        NusukGate::class,
        Books::class,
        Profitability::class,
        Forecast::class,
        Performance::class,
        WhoSendsUsPeople::class,
        // Linking a phone to the company's own bot (Phase 2d).
        Telegram::class,
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('company')
            ->path(self::PATH)
            ->brandName(fn (): string => filament()->getTenant()?->getAttribute('name') ?? 'Your company')
            ->login(Login::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Brand::WINE_SCALE,
                'gray' => Color::Stone,
            ])
            ->tenant(Partner::class, slugAttribute: 'slug', ownershipRelationship: 'company')
            ->tenantMenu(fn (): bool => (auth()->user()?->companies()->count() ?? 0) > 1)
            ->tenantMiddleware([UseTenantCompany::class, RecordSupportVisit::class], isPersistent: true)
            // The company panel's own pages live in the Companies module
            // (docs/MODULES_PLAN.md); the shared screens are listed below.
            ->discoverPages(in: base_path('modules/Companies/Filament/Pages'), for: 'Modules\Companies\Filament\Pages')
            ->resources(self::RESOURCES)
            ->pages([Dashboard::class, ...self::PAGES])
            // The home page's cards and charts (Phase 3g), each behind its permission.
            ->widgets([DashboardStats::class, MoneyChart::class, SeatsChart::class])
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => BottomNav::render())
            // An overdue invoice from Rihla, to the company's Owners (billing).
            ->renderHook(PanelsRenderHook::PAGE_START, fn (): string => OverdueInvoiceBanner::render())
            // The leader pages, for this company (companies plan, Phase 4e):
            // the same sign-in, so a leader opens them from here.
            ->navigationItems([
                NavigationItem::make('Leader pages')
                    ->icon('heroicon-o-flag')
                    ->group('Travel')
                    ->url(fn (): string => CompanyRequest::route('leader.index', ['locale' => app()->getLocale()]), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => auth()->user()?->can('attendance.create') === true),
            ])
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
