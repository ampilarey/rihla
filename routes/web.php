<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\GuideStepController as AdminGuideStepController;
use App\Http\Controllers\Admin\HeroBannerController;
use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\TripController as AdminTripController;
use App\Http\Controllers\Admin\WhyFeatureController;
use App\Http\Controllers\Admin\WhySectionController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DevicesController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EnquiryController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\LeaderController;
use App\Http\Controllers\LearningController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PackageComparisonController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentSlipController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SecondFactorController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StaffInvoiceController;
use App\Http\Controllers\StaysController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\WaitlistController;
use App\Http\Controllers\ZiyarahController;
use App\Models\GuideStep;
use App\Models\Media;
use App\Models\Trip;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Public routes
//
// Every public URL carries its locale as the first path segment (/en/trips,
// /dv/trips). Previously both languages shared one path and the choice lived
// in the session, so each page had exactly one indexable URL — search engines
// could only ever see English — and a link shared with someone showed them
// whichever language *they* had last selected, not the one the sender saw.
//
// Views did not have to change: SetLocale calls URL::defaults(['locale' => …]),
// so route('trips.show', $slug) keeps working and emits the right prefix.
Route::prefix('{locale}')->where(['locale' => 'en|dv|ar'])->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    // Additive. /trips is untouched and still served by TripController; a
    // package is the product and a departure one dated run of it, which is
    // what makes seats, a countdown, an itinerary and hotel distances
    // possible. Nothing redirects between the two yet — retiring `trips` is
    // a decision for after a full season runs on the new model.
    Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
    // Before the {slug} route, or "compare" is read as a package slug.
    Route::get('/packages/compare', PackageComparisonController::class)->name('packages.compare');
    Route::get('/packages/{slug}', [PackageController::class, 'show'])->name('packages.show');

    // Checkout. Step one hangs off the package because that is where the
    // visitor is standing; the rest do not carry an identifier at all — the
    // booking is held in the session, because a reference in the path would
    // let anyone who guessed one read a stranger's passport details.
    Route::get('/packages/{slug}/book', [BookingController::class, 'start'])->name('booking.start');
    Route::post('/packages/{slug}/book', [BookingController::class, 'hold'])->name('booking.hold');
    Route::get('/book/travellers', [BookingController::class, 'travellers'])->name('booking.travellers');
    Route::post('/book/travellers', [BookingController::class, 'storeTravellers'])->name('booking.travellers.store');
    Route::get('/book/review', [BookingController::class, 'review'])->name('booking.review');
    Route::post('/book/review', [BookingController::class, 'confirm'])->name('booking.confirm');
    Route::get('/book/confirmation', [BookingController::class, 'confirmation'])->name('booking.confirmation');

    // The waiting list for a full departure. The claim link hands over seats
    // that are already held, so it is signed and expires with the offer —
    // a guessable URL would let anybody take somebody else's.
    Route::post('/packages/{slug}/waitlist', [WaitlistController::class, 'join'])->name('waitlist.join');
    Route::get('/waitlist/claim/{entry}', [WaitlistController::class, 'claim'])
        ->name('waitlist.claim')
        ->middleware('signed');

    // The Pilgrim Portal (§6.1).
    //
    // Two routes are outside the gate on purpose: the door, which spends a
    // link and is the only place a token ever appears, and the locked page
    // it lands on when the link will not work. Everything else is behind
    // `portal`, which reads the booking from the session — no portal URL
    // carries an identifier, so editing the address bar shows a visitor
    // their own booking or nothing.
    Route::get('/portal/enter/{token}', [PortalController::class, 'enter'])->name('portal.enter');
    Route::get('/portal/locked', [PortalController::class, 'locked'])->name('portal.locked');

    Route::middleware('portal')->group(function () {
        Route::get('/portal', [PortalController::class, 'home'])->name('portal.home');
        Route::get('/portal/documents', [PortalController::class, 'documents'])->name('portal.documents');
        Route::post('/portal/documents', [PortalController::class, 'storeDocument'])->name('portal.documents.store');
        Route::post('/portal/payments', [PortalController::class, 'storePayment'])->name('portal.payments.store');
        Route::post('/portal/leave', [PortalController::class, 'leave'])->name('portal.leave');

        // §6.2 puts the family-sharing controls in the pilgrim's hands, so
        // they live behind the pilgrim's own gate and there is no staff
        // path to any of them.
        Route::get('/portal/family', [PortalController::class, 'family'])->name('portal.family');
        Route::post('/portal/family', [PortalController::class, 'storeFamilyLink'])->name('portal.family.store');
        Route::patch('/portal/family/{familyAccess}', [PortalController::class, 'updateFamilyLink'])->name('portal.family.update');
        Route::delete('/portal/family/{familyAccess}', [PortalController::class, 'revokeFamilyLink'])->name('portal.family.revoke');

        // The booking's own paperwork. The invoice takes no identifier at
        // all; the receipt names a payment and the controller proves it
        // belongs to this booking before rendering a byte.
        // The Learning Academy (§7.3). Behind the portal session, because
        // the plan is personal: it names what this pilgrim has and has not
        // read, and is keyed to their departure date.
        Route::get('/portal/learn', [LearningController::class, 'index'])->name('learning.index');
        Route::get('/portal/learn/{slug}', [LearningController::class, 'show'])->name('learning.show');
        Route::post('/portal/learn/{slug}', [LearningController::class, 'submitQuiz'])->name('learning.quiz');

        // Ask a Scholar (§6.4). Declared before /learn/{slug} would catch
        // it — "questions" is not a module slug, and the ordering trap is
        // the same one the packages and ziyarah routes carry a note about.
        Route::get('/portal/questions', [LearningController::class, 'questions'])->name('learning.questions');
        Route::post('/portal/questions', [LearningController::class, 'askQuestion'])->name('learning.questions.store');
        // §9.6's assistant, in front of the scholar's queue rather than
        // beside it. It answers only from approved content or hands over.
        Route::post('/portal/questions/guide', [LearningController::class, 'askTheGuide'])->name('learning.questions.guide');

        Route::get('/portal/invoice', [InvoiceController::class, 'invoice'])->name('portal.invoice');
        Route::get('/portal/receipt/{payment}', [InvoiceController::class, 'receipt'])->name('portal.receipt');
    });

    // The Tour Leader Portal (§6.3).
    //
    // Behind `auth` and a permission, not a portal token: a leader is a
    // member of staff with a login, and these pages carry pilgrim names,
    // ages and who is sharing a room with whom. The controller narrows
    // further to the departures this person's profile is assigned to, so an
    // account with no profile sees nothing rather than everything.
    Route::middleware(['auth', 'can:attendance.create'])->prefix('leader')->name('leader.')->group(function () {
        Route::get('/', [LeaderController::class, 'index'])->name('index');
        Route::get('/{departure}', [LeaderController::class, 'departure'])->name('departure');
        Route::get('/{departure}/snapshot', [LeaderController::class, 'snapshot'])->name('snapshot');
        Route::get('/{departure}/count/{rollCall}', [LeaderController::class, 'count'])->name('count');

        // Everything the phone queued while it had no signal. A POST, and
        // therefore never served from the service worker's cache — a
        // replayed write out of a cache would be a mark nobody made.
        Route::post('/sync', [LeaderController::class, 'sync'])->name('sync');
    });

    // The Family Portal (§6.2).
    //
    // The same shape as the Pilgrim Portal and deliberately not the same
    // gate: `family`, not `portal`. A family session must never satisfy a
    // pilgrim-portal check — the two carry different amounts of somebody's
    // life, and sharing the middleware is how they end up sharing a bug.
    Route::get('/family/enter/{token}', [FamilyController::class, 'enter'])->name('family.enter');
    Route::get('/family/locked', [FamilyController::class, 'locked'])->name('family.locked');

    Route::middleware('family')->group(function () {
        Route::get('/family', [FamilyController::class, 'home'])->name('family.home');
        Route::post('/family/leave', [FamilyController::class, 'leave'])->name('family.leave');
    });

    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');

    Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
    Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');

    Route::get('/trips', [TripController::class, 'index'])->name('trips.index');
    Route::get('/trips/{slug}', [TripController::class, 'show'])->name('trips.show');
    Route::get('/gallery', [MediaController::class, 'gallery'])->name('gallery');
    Route::get('/social', [PageController::class, 'social'])->name('social');
    Route::get('/contact', [PageController::class, 'contact'])->name('contact');
    // §8.1: a message sent here becomes a tracked lead with somewhere to
    // put an owner and a next action, rather than another line in a shared
    // inbox.
    Route::post('/contact', [EnquiryController::class, 'store'])->name('enquiries.store');
    Route::get('/guide', [PageController::class, 'guide'])->name('guide');
    Route::get('/guide/pdf', [PageController::class, 'guidePdf'])->name('guide.pdf');

    // The Stays line — §15.3 (Phase 8.2), §15.4 (Phase 9.4). Each strand
    // is gated by the service registry: off is a 404, coming_soon shows the
    // page and takes an enquiry, on adds the listing and booking.
    //
    // The hub is not gated by the middleware, because no single service
    // owns it — the controller 404s when every strand is off.
    Route::get('/stays', [StaysController::class, 'index'])->name('stays.index');
    Route::get('/stays/guesthouses', [StaysController::class, 'guesthouses'])
        ->middleware('service:stays_guesthouses')->name('stays.guesthouses');
    Route::get('/stays/island-holidays', [StaysController::class, 'islandHolidays'])
        ->middleware('service:stays_island_holidays')->name('stays.island-holidays');
    Route::get('/stays/rooms', [StaysController::class, 'rooms'])
        ->middleware('service:stays_rooms')->name('stays.rooms');

    // **Declared last on purpose.** Laravel matches in order, so this must
    // come after the three strand routes or a property slugged
    // "guesthouses" would answer for them. Property::RESERVED_SLUGS is the
    // other half of that guard — it stops such a slug being minted at all,
    // because a shared link that silently goes somewhere else is worse than
    // one that 404s.
    //
    // The share kit — §15.4 (Phase 9.5). Both declared *before* the
    // catch-all below, for the same ordering reason: `{property}` would
    // otherwise swallow `card.png` as a slug.
    Route::get('/stays/{property}/card.png', [StaysController::class, 'shareCard'])->name('stays.card');
    Route::get('/stays/{property}/sheet.pdf', [StaysController::class, 'factSheet'])->name('stays.sheet');

    Route::get('/stays/{property}', [StaysController::class, 'show'])->name('stays.show');

    // The Ziyarah Guide (§7.2). The manifest is declared before {slug} or
    // "offline" is read as a location slug — the same ordering trap the
    // package comparison route carries a comment about.
    // The Knowledge Centre a pilgrim can actually read (§7.1). Built with
    // §9.6's assistant, which must cite it — and a citation nobody can open
    // and check is not a citation.
    Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('/knowledge/{slug}', [KnowledgeController::class, 'show'])->name('knowledge.show');

    Route::get('/ziyarah', [ZiyarahController::class, 'index'])->name('ziyarah.index');
    Route::get('/ziyarah/offline', [ZiyarahController::class, 'offlineManifest'])->name('ziyarah.manifest');
    Route::get('/ziyarah/{slug}', [ZiyarahController::class, 'show'])->name('ziyarah.show');
});

// The bare domain picks a language from the session, then Accept-Language, and
// forwards. Deliberately a 302: the target varies per visitor, so a permanent
// redirect would be cached by the browser and pin them to one language.
Route::get('/', [PageController::class, 'root'])->name('root');

// The pre-prefix URLs. Existing links, bookmarks and anything already indexed
// keep working and are moved onto the canonical localised URL.
foreach ([
    'trips' => 'trips.index',
    'gallery' => 'gallery',
    'social' => 'social',
    'contact' => 'contact',
    'guide' => 'guide',
    'guide/pdf' => 'guide.pdf',
] as $legacyPath => $routeName) {
    Route::get($legacyPath, fn () => redirect()->route($routeName, [], 301));
}

Route::get('trips/{slug}', fn (string $slug) => redirect()->route('trips.show', $slug, 301));

// One sitemap covering both languages, each entry carrying xhtml:link
// alternates so the two locales are read as one page in two languages rather
// than as duplicate content competing with each other.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Not localised in the path: the caller states the language it wants with
// ?locale=, and the response is JSON rather than a page to be indexed.
Route::get('/api/guide-steps', [PageController::class, 'guideStepsApi'])->name('guide.api');

// Locale switching. The parameter is `code`, not `locale`, so that the default
// registered by URL::defaults() does not get substituted into it — that would
// make every switch link point at the language the visitor is already reading.
Route::get('/lang/{code}', [PageController::class, 'setLocale'])->name('locale.switch');

// Authenticated routes
//
// `dashboard` is the name Breeze's auth controllers redirect to after
// registration, email verification and password confirmation. The name existed
// only as `admin.dashboard` (it sits inside the `admin.` group below), so every
// one of those flows threw RouteNotFoundException and returned a 500. Admins
// are forwarded to the admin panel; everyone else gets the plain dashboard,
// which is why this is not simply an alias for the admin route.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return Gate::allows('admin')
            ? redirect()->route('admin.dashboard')
            : view('dashboard');
    })->name('dashboard');

    // ProfileController and resources/views/profile/ shipped with the app but
    // were never routed, so the controller was unreachable and `profile.edit`
    // — referenced by the profile forms themselves — did not resolve.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // The only route to a document file. Signed as well as authenticated:
    // the disk is private and not servable, so this is the single door, and
    // it records who walked through it. Not locale-prefixed — it returns a
    // file, not a page.
    Route::get('/documents/{version}/download', [DocumentController::class, 'download'])
        ->name('documents.download')
        ->middleware('signed');

    // The only route to a transfer slip, on the same terms as a document:
    // signed as well as authenticated, on a private disk, audited on the
    // way through. A slip carries an account number and a name.
    Route::get('/payments/{payment}/slip', [PaymentSlipController::class, 'show'])
        ->name('payments.slip')
        ->middleware('signed');

    // The same two documents, for staff. Authenticated and authorised by the
    // booking policy rather than by a portal session, and deliberately not
    // signed: a member of staff opening a booking they may already read is
    // not the same threat as a link sent over WhatsApp.
    Route::get('/staff-documents/booking/{booking}/invoice', [StaffInvoiceController::class, 'invoice'])
        ->name('staff.invoice');
    Route::get('/staff-documents/payment/{payment}/receipt', [StaffInvoiceController::class, 'receipt'])
        ->name('staff.receipt');
});

// Admin routes (require authentication and admin privileges)
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        $tripCount = Trip::count();
        $mediaCount = Media::count();
        $guideStepCount = GuideStep::count();

        return view('admin.dashboard', compact('tripCount', 'mediaCount', 'guideStepCount'));
    })->name('dashboard');

    Route::resource('trips', AdminTripController::class);
    Route::resource('media', AdminMediaController::class);

    Route::resource('guide-steps', AdminGuideStepController::class);
    Route::resource('hero-banners', HeroBannerController::class);

    // Hero banner additional routes
    Route::post('hero-banners/{heroBanner}/toggle-status', [HeroBannerController::class, 'toggleStatus'])->name('hero-banners.toggle-status');
    Route::post('hero-banners/update-order', [HeroBannerController::class, 'updateOrder'])->name('hero-banners.update-order');

    // Guide step additional routes
    Route::post('guide-steps/update-order', [AdminGuideStepController::class, 'updateOrder'])->name('guide-steps.update-order');
    Route::post('guide-steps/{guideStep}/toggle-status', [AdminGuideStepController::class, 'toggleStatus'])->name('guide-steps.toggle-status');
    Route::post('guide-steps/bulk-update-status', [AdminGuideStepController::class, 'bulkUpdateStatus'])->name('guide-steps.bulk-update-status');

    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [AdminSettingController::class, 'update'])->name('settings.update');

    // Why Section Management
    Route::resource('why-sections', WhySectionController::class)
        ->only(['index', 'edit', 'update'])
        ->parameters(['why-sections' => 'section']);

    // `index` is excluded alongside show and create because
    // WhyFeatureController has no such method — the route existed and
    // returned a 500. Features are listed and added from the section's own
    // edit screen, so there is nothing for a separate index to show.
    Route::resource('why-sections.features', WhyFeatureController::class)
        ->shallow()
        ->except(['show', 'create', 'index'])
        ->parameters(['why-sections' => 'section', 'features' => 'feature']);
});

/*
|--------------------------------------------------------------------------
| The second step at sign-in — §10.4
|--------------------------------------------------------------------------
|
| Outside the Filament panel on purpose. The challenge has to be reachable
| by somebody who has not yet passed it, and a screen inside the panel that
| the panel's own middleware guards cannot be the way through that
| middleware.
|
| Not locale-prefixed: these are staff screens, and the panel is English.
|
*/
Route::middleware('auth')->group(function () {
    Route::get('/two-factor', [SecondFactorController::class, 'settings'])->name('mfa.settings');
    Route::get('/two-factor/set-up', [SecondFactorController::class, 'enrol'])->name('mfa.enrol');
    Route::post('/two-factor/set-up', [SecondFactorController::class, 'confirm'])->name('mfa.confirm');
    Route::get('/two-factor/challenge', [SecondFactorController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/two-factor/challenge', [SecondFactorController::class, 'verify'])->name('mfa.verify');
    Route::post('/two-factor/off', [SecondFactorController::class, 'disable'])->name('mfa.disable');

    // Where this account is signed in. Beside the second factor because
    // it answers the same question from the other side: one says how hard
    // it is to get in, the other says who already is.
    Route::get('/devices', [DevicesController::class, 'index'])->name('devices.index');
    Route::delete('/devices/others', [DevicesController::class, 'destroyOthers'])->name('devices.destroyOthers');
    Route::delete('/devices/{device}', [DevicesController::class, 'destroy'])->name('devices.destroy');
});

require __DIR__.'/auth.php';
