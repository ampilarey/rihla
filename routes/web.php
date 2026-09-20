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
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PackageComparisonController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PaymentSlipController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\WaitlistController;
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
Route::prefix('{locale}')->where(['locale' => 'en|dv'])->group(function () {
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
    });

    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');

    Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
    Route::get('/articles/{slug}', [ArticleController::class, 'show'])->name('articles.show');

    Route::get('/trips', [TripController::class, 'index'])->name('trips.index');
    Route::get('/trips/{slug}', [TripController::class, 'show'])->name('trips.show');
    Route::get('/gallery', [MediaController::class, 'gallery'])->name('gallery');
    Route::get('/social', [PageController::class, 'social'])->name('social');
    Route::get('/contact', [PageController::class, 'contact'])->name('contact');
    Route::get('/guide', [PageController::class, 'guide'])->name('guide');
    Route::get('/guide/pdf', [PageController::class, 'guidePdf'])->name('guide.pdf');
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

require __DIR__.'/auth.php';
