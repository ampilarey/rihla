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
use Modules\Stays\Models\BlockedDate;
use Modules\Stays\Models\CalendarFeed;
use Modules\Stays\Models\HostInvitation;
use Modules\Stays\Models\HostMembership;
use Modules\Stays\Models\HostPage;
use Modules\Stays\Models\HostStatement;
use Modules\Stays\Models\Payout;
use Modules\Stays\Models\Property;
use Modules\Stays\Models\PropertyAddon;
use Modules\Stays\Models\PropertyPhoto;
use Modules\Stays\Models\PropertyUnit;
use Modules\Stays\Models\Rate;
use Modules\Stays\Models\Review;
use Modules\Stays\Models\ReviewInvitation;
use Modules\Stays\Models\RoomType;
use Modules\Stays\Models\Stay;
use Modules\Stays\Models\StayAccess;
use Modules\Stays\Models\StayCharge;
use Modules\Stays\Models\StayDiscount;
use Modules\Stays\Models\StayGuest;
use Modules\Stays\Models\StayMessage;
use Modules\Stays\StaysServiceProvider;
use Modules\Telegram\Models\TelegramLink;
use Modules\Telegram\Models\TelegramLinkCode;
use Modules\Telegram\Models\TelegramUpdate;
use Modules\Telegram\TelegramServiceProvider;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\BoatTicket;
use Modules\Transport\Models\OperatorMembership;
use Modules\Transport\Models\OperatorStatement;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\TransportRequest;
use Modules\Transport\Models\TransportRequestEvent;
use Modules\Transport\Models\Vessel;
use Modules\Transport\TransportServiceProvider;
use Modules\Travel\Models\TravelQuoteOption;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Models\TravelRequestEvent;
use Modules\Travel\TravelServiceProvider;
use Modules\Umrah\Models\Agreement;
use Modules\Umrah\Models\Announcement;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\BookingLine;
use Modules\Umrah\Models\BookingStatusTransition;
use Modules\Umrah\Models\BookingTraveller;
use Modules\Umrah\Models\BroadcastDelivery;
use Modules\Umrah\Models\BusGroup;
use Modules\Umrah\Models\Card;
use Modules\Umrah\Models\CrmTask;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureChecklistItem;
use Modules\Umrah\Models\DepartureCost;
use Modules\Umrah\Models\DepartureEvent;
use Modules\Umrah\Models\DepartureExcursion;
use Modules\Umrah\Models\DepartureFlight;
use Modules\Umrah\Models\DepartureHotel;
use Modules\Umrah\Models\DepartureTransfer;
use Modules\Umrah\Models\Discount;
use Modules\Umrah\Models\Document;
use Modules\Umrah\Models\DocumentExtraction;
use Modules\Umrah\Models\DocumentVersion;
use Modules\Umrah\Models\EmergencyBroadcast;
use Modules\Umrah\Models\Enquiry;
use Modules\Umrah\Models\EnquiryNote;
use Modules\Umrah\Models\ExcursionBooking;
use Modules\Umrah\Models\ExcursionParticipant;
use Modules\Umrah\Models\FamilyAccess;
use Modules\Umrah\Models\FlightGroup;
use Modules\Umrah\Models\Incident;
use Modules\Umrah\Models\IncidentNote;
use Modules\Umrah\Models\ItineraryItem;
use Modules\Umrah\Models\NusukPermit;
use Modules\Umrah\Models\NusukPermitEvent;
use Modules\Umrah\Models\OperationsLogEntry;
use Modules\Umrah\Models\Package;
use Modules\Umrah\Models\PaymentSchedule;
use Modules\Umrah\Models\PortalAccess;
use Modules\Umrah\Models\PriceTier;
use Modules\Umrah\Models\Quotation;
use Modules\Umrah\Models\RollCall;
use Modules\Umrah\Models\RollCallMark;
use Modules\Umrah\Models\Room;
use Modules\Umrah\Models\RoomAssignment;
use Modules\Umrah\Models\SeatHold;
use Modules\Umrah\Models\Traveller;
use Modules\Umrah\Models\VisaApplication;
use Modules\Umrah\Models\VisaApplicationEvent;
use Modules\Umrah\Models\WaitlistEntry;
use Modules\Umrah\UmrahServiceProvider;

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
        StaysServiceProvider::class,
        UmrahServiceProvider::class,
        TransportServiceProvider::class,
        TravelServiceProvider::class,
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
        'App\Models\BlockedDate' => BlockedDate::class,
        'App\Models\CalendarFeed' => CalendarFeed::class,
        'App\Models\HostInvitation' => HostInvitation::class,
        'App\Models\HostMembership' => HostMembership::class,
        'App\Models\HostPage' => HostPage::class,
        'App\Models\HostStatement' => HostStatement::class,
        'App\Models\Payout' => Payout::class,
        'App\Models\Property' => Property::class,
        'App\Models\PropertyAddon' => PropertyAddon::class,
        'App\Models\PropertyPhoto' => PropertyPhoto::class,
        'App\Models\PropertyUnit' => PropertyUnit::class,
        'App\Models\Rate' => Rate::class,
        'App\Models\Review' => Review::class,
        'App\Models\ReviewInvitation' => ReviewInvitation::class,
        'App\Models\RoomType' => RoomType::class,
        'App\Models\Stay' => Stay::class,
        'App\Models\StayAccess' => StayAccess::class,
        'App\Models\StayCharge' => StayCharge::class,
        'App\Models\StayDiscount' => StayDiscount::class,
        'App\Models\StayGuest' => StayGuest::class,
        'App\Models\StayMessage' => StayMessage::class,
        'App\Models\Package' => Package::class,
        'App\Models\PriceTier' => PriceTier::class,
        'App\Models\Departure' => Departure::class,
        'App\Models\DepartureChecklistItem' => DepartureChecklistItem::class,
        'App\Models\DepartureCost' => DepartureCost::class,
        'App\Models\DepartureEvent' => DepartureEvent::class,
        'App\Models\DepartureExcursion' => DepartureExcursion::class,
        'App\Models\DepartureFlight' => DepartureFlight::class,
        'App\Models\DepartureHotel' => DepartureHotel::class,
        'App\Models\DepartureTransfer' => DepartureTransfer::class,
        'App\Models\ItineraryItem' => ItineraryItem::class,
        'App\Models\Booking' => Booking::class,
        'App\Models\BookingLine' => BookingLine::class,
        'App\Models\BookingStatusTransition' => BookingStatusTransition::class,
        'App\Models\BookingTraveller' => BookingTraveller::class,
        'App\Models\Discount' => Discount::class,
        'App\Models\PaymentSchedule' => PaymentSchedule::class,
        'App\Models\SeatHold' => SeatHold::class,
        'App\Models\WaitlistEntry' => WaitlistEntry::class,
        'App\Models\Traveller' => Traveller::class,
        'App\Models\Agreement' => Agreement::class,
        'App\Models\Document' => Document::class,
        'App\Models\DocumentExtraction' => DocumentExtraction::class,
        'App\Models\DocumentVersion' => DocumentVersion::class,
        'App\Models\VisaApplication' => VisaApplication::class,
        'App\Models\VisaApplicationEvent' => VisaApplicationEvent::class,
        'App\Models\NusukPermit' => NusukPermit::class,
        'App\Models\NusukPermitEvent' => NusukPermitEvent::class,
        'App\Models\ExcursionBooking' => ExcursionBooking::class,
        'App\Models\ExcursionParticipant' => ExcursionParticipant::class,
        'App\Models\BusGroup' => BusGroup::class,
        'App\Models\Card' => Card::class,
        'App\Models\FlightGroup' => FlightGroup::class,
        'App\Models\Room' => Room::class,
        'App\Models\RoomAssignment' => RoomAssignment::class,
        'App\Models\RollCall' => RollCall::class,
        'App\Models\RollCallMark' => RollCallMark::class,
        'App\Models\Incident' => Incident::class,
        'App\Models\IncidentNote' => IncidentNote::class,
        'App\Models\OperationsLogEntry' => OperationsLogEntry::class,
        'App\Models\Announcement' => Announcement::class,
        'App\Models\EmergencyBroadcast' => EmergencyBroadcast::class,
        'App\Models\BroadcastDelivery' => BroadcastDelivery::class,
        'App\Models\FamilyAccess' => FamilyAccess::class,
        'App\Models\PortalAccess' => PortalAccess::class,
        'App\Models\CrmTask' => CrmTask::class,
        'App\Models\Enquiry' => Enquiry::class,
        'App\Models\EnquiryNote' => EnquiryNote::class,
        'App\Models\Quotation' => Quotation::class,
    ];

    /**
     * Models written in a module from the start, which never had another
     * class name, so nothing in the database holds an old one. Every module
     * model is in this list or in LEGACY_NAMES — ModulesTest fails
     * otherwise — so a model moved out of app/ cannot slip through as
     * "new" and leave its rows unresolvable.
     *
     * @var list<class-string<Model>>
     */
    public const BORN_IN_MODULES = [
        Vessel::class,
        BoatRoute::class,
        Sailing::class,
        BoatBooking::class,
        BoatTicket::class,
        TransportRequest::class,
        TransportRequestEvent::class,
        OperatorMembership::class,
        OperatorStatement::class,
        TravelRequest::class,
        TravelQuoteOption::class,
        TravelRequestEvent::class,
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
