<?php

use App\Http\Controllers\Admin\GuideStepController as AdminGuideStepController;
use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\TripController as AdminTripController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\TripController;
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

// Admin routes (require authentication and admin privileges)
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', function () {
        $tripCount = \App\Models\Trip::count();
        $mediaCount = \App\Models\Media::count();
        $guideStepCount = \App\Models\GuideStep::count();

        return view('admin.dashboard', compact('tripCount', 'mediaCount', 'guideStepCount'));
    })->name('dashboard');

    Route::resource('trips', AdminTripController::class);
    Route::resource('media', AdminMediaController::class);
    
    
    Route::resource('guide-steps', AdminGuideStepController::class);
    Route::resource('hero-banners', \App\Http\Controllers\Admin\HeroBannerController::class);
    
    // Hero banner additional routes
    Route::post('hero-banners/{heroBanner}/toggle-status', [\App\Http\Controllers\Admin\HeroBannerController::class, 'toggleStatus'])->name('hero-banners.toggle-status');
    Route::post('hero-banners/update-order', [\App\Http\Controllers\Admin\HeroBannerController::class, 'updateOrder'])->name('hero-banners.update-order');
    
    // Guide step additional routes
    Route::post('guide-steps/update-order', [AdminGuideStepController::class, 'updateOrder'])->name('guide-steps.update-order');
    Route::post('guide-steps/{guideStep}/toggle-status', [AdminGuideStepController::class, 'toggleStatus'])->name('guide-steps.toggle-status');
    Route::post('guide-steps/bulk-update-status', [AdminGuideStepController::class, 'bulkUpdateStatus'])->name('guide-steps.bulk-update-status');
    
    Route::get('settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::post('settings', [AdminSettingController::class, 'update'])->name('settings.update');
    
    // Why Section Management
    Route::resource('why-sections', \App\Http\Controllers\Admin\WhySectionController::class)
        ->only(['index', 'edit', 'update'])
        ->parameters(['why-sections' => 'section']);

    Route::resource('why-sections.features', \App\Http\Controllers\Admin\WhyFeatureController::class)
        ->shallow()
        ->except(['show', 'create'])
        ->parameters(['why-sections' => 'section', 'features' => 'feature']);
});

require __DIR__.'/auth.php';
