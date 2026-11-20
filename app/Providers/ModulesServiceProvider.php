<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Modules\Companies\CompaniesServiceProvider;
use Modules\Companies\Models\CompanyInvoice;
use Modules\Companies\Models\CompanyMembership;
use Modules\Companies\Models\CompanySite;
use Modules\Companies\Models\SupportGrant;
use Modules\Companies\Models\SupportVisit;
use Modules\Content\ContentServiceProvider;
use Modules\Content\Models\Article;
use Modules\Content\Models\ArticleReference;
use Modules\Content\Models\AssistantExchange;
use Modules\Content\Models\GuideStep;
use Modules\Content\Models\HeroBanner;
use Modules\Content\Models\KnowledgeArticle;
use Modules\Content\Models\LearningModule;
use Modules\Content\Models\LearningPath;
use Modules\Content\Models\LocationMisconception;
use Modules\Content\Models\ModuleCompletion;
use Modules\Content\Models\Person;
use Modules\Content\Models\QuizOption;
use Modules\Content\Models\QuizQuestion;
use Modules\Content\Models\ScholarQuestion;
use Modules\Content\Models\Trip;
use Modules\Content\Models\WhyFeature;
use Modules\Content\Models\WhySection;
use Modules\Content\Models\ZiyarahLocation;
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
        ContentServiceProvider::class,
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
        'App\Models\Article' => Article::class,
        'App\Models\ArticleReference' => ArticleReference::class,
        'App\Models\AssistantExchange' => AssistantExchange::class,
        'App\Models\GuideStep' => GuideStep::class,
        'App\Models\HeroBanner' => HeroBanner::class,
        'App\Models\KnowledgeArticle' => KnowledgeArticle::class,
        'App\Models\LearningModule' => LearningModule::class,
        'App\Models\LearningPath' => LearningPath::class,
        'App\Models\LocationMisconception' => LocationMisconception::class,
        'App\Models\ModuleCompletion' => ModuleCompletion::class,
        'App\Models\Person' => Person::class,
        'App\Models\QuizOption' => QuizOption::class,
        'App\Models\QuizQuestion' => QuizQuestion::class,
        'App\Models\ScholarQuestion' => ScholarQuestion::class,
        'App\Models\Trip' => Trip::class,
        'App\Models\WhyFeature' => WhyFeature::class,
        'App\Models\WhySection' => WhySection::class,
        'App\Models\ZiyarahLocation' => ZiyarahLocation::class,
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

        // Factories stay in database/factories, named after the model. Laravel
        // guesses both directions from App\Models, so a model in a module
        // would look for Database\Factories\Modules\…\XFactory and a factory
        // would build an App\Models class that no longer exists.
        Factory::guessFactoryNamesUsing(
            fn (string $model): string => 'Database\\Factories\\'.class_basename($model).'Factory',
        );
        Factory::guessModelNamesUsing(function (Factory $factory): string {
            $name = Str::replaceLast('Factory', '', class_basename($factory));

            foreach (['App\\Models\\', ...array_map(fn (string $module): string => 'Modules\\'.$module.'\\Models\\', self::names())] as $namespace) {
                if (class_exists($namespace.$name)) {
                    return $namespace.$name;
                }
            }

            return 'App\\Models\\'.$name;
        });
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
