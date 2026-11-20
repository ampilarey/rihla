<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Modules\Companies\CompaniesServiceProvider;
use Modules\Companies\Models\CompanyInvoice;
use Modules\Companies\Models\CompanyMembership;
use Modules\Companies\Models\CompanySite;
use Modules\Companies\Models\SupportGrant;
use Modules\Companies\Models\SupportVisit;
use Modules\Telegram\Models\TelegramLink;
use Modules\Telegram\Models\TelegramLinkCode;
use Modules\Telegram\Models\TelegramUpdate;
use Modules\Telegram\TelegramServiceProvider;

/**
 * The modules — docs/MODULES_PLAN.md. One folder per line of business under
 * `modules/`, each with its own service provider, registered here.
 */
class ModulesServiceProvider extends ServiceProvider
{
    /** @var list<class-string<ServiceProvider>> */
    public const MODULES = [
        TelegramServiceProvider::class,
        CompaniesServiceProvider::class,
    ];

    /**
     * Every moved model's class name *before* it moved, mapped to its class
     * now. The database stores model class names in four places
     * (`payments.payable_type`, `notices.noticeable_type`,
     * `audit_logs.auditable_type` and spatie's `model_type`); pinned here
     * through the morph map, a moved model keeps writing — and reading — the
     * name its rows already hold, so a move needs no data migration. A
     * model added to a module from now on is added here in the same commit;
     * ModulesTest fails otherwise.
     *
     * @var array<string, class-string<Model>>
     */
    public const LEGACY_NAMES = [
        'App\Models\TelegramLink' => TelegramLink::class,
        'App\Models\TelegramLinkCode' => TelegramLinkCode::class,
        'App\Models\TelegramUpdate' => TelegramUpdate::class,
        'App\Models\CompanyInvoice' => CompanyInvoice::class,
        'App\Models\CompanyMembership' => CompanyMembership::class,
        'App\Models\CompanySite' => CompanySite::class,
        'App\Models\SupportGrant' => SupportGrant::class,
        'App\Models\SupportVisit' => SupportVisit::class,
    ];

    public function register(): void
    {
        foreach (self::MODULES as $module) {
            $this->app->register($module);
        }
    }

    public function boot(): void
    {
        Relation::morphMap(self::LEGACY_NAMES);
    }

    /**
     * Each module's folder, for the panels to discover its screens.
     *
     * @return list<string> module names, e.g. ['Telegram']
     */
    public static function names(): array
    {
        return array_map(
            fn (string $provider): string => explode('\\', $provider)[1],
            self::MODULES,
        );
    }
}
