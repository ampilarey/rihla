<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\EmergencyBroadcast;
use App\Models\Enquiry;
use App\Models\Incident;
use App\Models\Media;
use App\Models\Notice;
use App\Models\OperationsLogEntry;
use App\Models\Payment;
use App\Models\RollCall;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\EmergencyBroadcastPolicy;
use App\Policies\EnquiryPolicy;
use App\Policies\IncidentPolicy;
use App\Policies\MediaPolicy;
use App\Policies\NoticePolicy;
use App\Policies\OperationsLogEntryPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\RollCallPolicy;
use App\Policies\RoomPolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyFeatures;
use App\Support\CompanyRequest;
use App\Support\CompanyRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Content\Models\Article;
use Modules\Content\Models\GuideStep;
use Modules\Content\Models\HeroBanner;
use Modules\Content\Models\KnowledgeArticle;
use Modules\Content\Models\Person;
use Modules\Content\Models\Trip;
use Modules\Content\Models\WhyFeature;
use Modules\Content\Models\WhySection;
use Modules\Content\Policies\ArticlePolicy;
use Modules\Content\Policies\GuideStepPolicy;
use Modules\Content\Policies\HeroBannerPolicy;
use Modules\Content\Policies\KnowledgeArticlePolicy;
use Modules\Content\Policies\PersonPolicy;
use Modules\Content\Policies\TripPolicy;
use Modules\Content\Policies\WhyFeaturePolicy;
use Modules\Content\Policies\WhySectionPolicy;
use Modules\Stays\Support\HostContext;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureChecklistItem;
use Modules\Umrah\Models\DepartureFlight;
use Modules\Umrah\Models\DepartureTransfer;
use Modules\Umrah\Models\Document;
use Modules\Umrah\Models\NusukPermit;
use Modules\Umrah\Models\Package;
use Modules\Umrah\Models\VisaApplication;
use Modules\Umrah\Models\WaitlistEntry;
use Modules\Umrah\Policies\BookingPolicy;
use Modules\Umrah\Policies\DepartureChecklistItemPolicy;
use Modules\Umrah\Policies\DepartureFlightPolicy;
use Modules\Umrah\Policies\DeparturePolicy;
use Modules\Umrah\Policies\DepartureTransferPolicy;
use Modules\Umrah\Policies\DocumentPolicy;
use Modules\Umrah\Policies\NusukPermitPolicy;
use Modules\Umrah\Policies\PackagePolicy;
use Modules\Umrah\Policies\VisaApplicationPolicy;
use Modules\Umrah\Policies\WaitlistEntryPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Booking::class => BookingPolicy::class,
        Customer::class => CustomerPolicy::class,
        Document::class => DocumentPolicy::class,
        NusukPermit::class => NusukPermitPolicy::class,
        Payment::class => PaymentPolicy::class,
        Enquiry::class => EnquiryPolicy::class,
        Announcement::class => AnnouncementPolicy::class,
        EmergencyBroadcast::class => EmergencyBroadcastPolicy::class,
        Incident::class => IncidentPolicy::class,
        KnowledgeArticle::class => KnowledgeArticlePolicy::class,
        Notice::class => NoticePolicy::class,
        OperationsLogEntry::class => OperationsLogEntryPolicy::class,
        RollCall::class => RollCallPolicy::class,
        Room::class => RoomPolicy::class,
        DepartureFlight::class => DepartureFlightPolicy::class,
        DepartureChecklistItem::class => DepartureChecklistItemPolicy::class,
        DepartureTransfer::class => DepartureTransferPolicy::class,
        VisaApplication::class => VisaApplicationPolicy::class,
        WaitlistEntry::class => WaitlistEntryPolicy::class,
        Trip::class => TripPolicy::class,
        Package::class => PackagePolicy::class,
        Departure::class => DeparturePolicy::class,
        Person::class => PersonPolicy::class,
        Article::class => ArticlePolicy::class,
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
        // A record of another company is refused before anything else can
        // answer, the Super Admin grant below included (companies plan,
        // Phase 1b). The global scope already hides such a record from
        // every query; this is the second wall, for one reached some other
        // way (a scope removed, a model built by hand). Null otherwise, so
        // every other check runs as before.
        Gate::before(function ($user, string $ability, array $arguments) {
            foreach ($arguments as $argument) {
                if ($argument instanceof Model
                    && in_array(BelongsToCompany::class, class_uses_recursive($argument), true)
                    && $argument->exists
                    && ! app(CompanyContext::class)->owns($argument->getAttribute('company_id') === null ? null : (int) $argument->getAttribute('company_id'))) {
                    return false;
                }
            }

            return null;
        });

        // Inside a company's own panel (companies plan, Phase 2a) a
        // permission is answered from the person's role at that company,
        // never from spatie: a company's staff hold no spatie role, and a
        // Rihla Super Admin is nobody there unless they are a member.
        // Policy abilities ('update', 'view') fall through to the policies,
        // which ask for permissions by name and so land back here.
        Gate::before(function ($user, string $ability) {
            // The panel's tenant, or the company a print or download opened
            // from that panel names (Phase 2b); null for Rihla's requests.
            $company = CompanyRequest::current();

            if ($company === null || ! in_array($ability, Access::PERMISSIONS, true)) {
                return null;
            }

            // And only within what the company has paid for (Phase 5a): an
            // area that is off is refused, a read-only one only shown.
            return CompanyRole::allows($user->roleAtCompany($company), $ability)
                && CompanyFeatures::allows($company, $ability);
        });

        // Super Admin is granted everything here rather than by holding every
        // permission. A permission added later is then covered automatically,
        // instead of silently locking out the one role that must never be
        // locked out. Returning null — not false — lets every other check fall
        // through to the normal policy and permission chain.
        Gate::before(function ($user, string $ability) {
            // Inside /host a Super Admin is whatever their membership says
            // (site audit): the grant below answered before the host
            // policies could, so a reception member who was also staff
            // held every host ability at that host.
            if (HostContext::current() !== null || CompanyRequest::current() !== null) {
                return null;
            }

            return $user->hasRole(Access::SUPER_ADMIN) ? true : null;
        });

        // Spatie's permission check, registered here rather than by spatie
        // (config/permission.php), so that it runs *after* the company gate
        // above. Registered by spatie it ran first, and a person's Rihla
        // role answered inside a company's panel before the company could:
        // a Rihla manager on a view-only support grant could edit the
        // company's bookings (security review before the pilot). Inside a
        // company's panel the company gate has already answered every
        // permission by name, so this only ever speaks for Rihla.
        Gate::before(function ($user, string $ability, array $arguments) {
            if (CompanyRequest::current() !== null || ! method_exists($user, 'checkPermissionTo')) {
                return null;
            }

            $guard = is_string($arguments[0] ?? null) && ! class_exists($arguments[0]) ? $arguments[0] : null;

            return $user->checkPermissionTo($ability, $guard) ?: null;
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
