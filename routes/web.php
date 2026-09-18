<?php

use App\Http\Controllers\Admin\GuideStepController as AdminGuideStepController;
use App\Http\Controllers\Admin\HeroBannerController;
use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\TripController as AdminTripController;
use App\Http\Controllers\Admin\WhyFeatureController;
use App\Http\Controllers\Admin\WhySectionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TripController;
use App\Models\GuideStep;
use App\Models\Media;
use App\Models\Trip;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Public routes
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/trips', [TripController::class, 'index'])->name('trips.index');
Route::get('/trips/{slug}', [TripController::class, 'show'])->name('trips.show');
Route::get('/gallery', [MediaController::class, 'gallery'])->name('gallery');
Route::get('/social', [PageController::class, 'social'])->name('social');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::get('/guide', [PageController::class, 'guide'])->name('guide');
Route::get('/guide/pdf', [PageController::class, 'guidePdf'])->name('guide.pdf');
Route::get('/api/guide-steps', [PageController::class, 'guideStepsApi'])->name('guide.api');

// Locale switching
Route::get('/lang/{locale}', [PageController::class, 'setLocale'])->name('locale.switch');

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

    Route::get('settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [AdminSettingController::class, 'update'])->name('settings.update');

    // Why Section Management
    Route::resource('why-sections', WhySectionController::class)
        ->only(['index', 'edit', 'update'])
        ->parameters(['why-sections' => 'section']);

    Route::resource('why-sections.features', WhyFeatureController::class)
        ->shallow()
        ->except(['show', 'create'])
        ->parameters(['why-sections' => 'section', 'features' => 'feature']);
});

require __DIR__.'/auth.php';
