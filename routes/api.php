<?php

use App\Http\Controllers\Api\TestDeployWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'env' => config('app.env'),
    ]);
})->middleware('throttle:60,1');

// TEST-only immediate deploy trigger (GitHub Actions → cPanel). Disabled when
// TEST_DEPLOY_WEBHOOK_SECRET is unset; always 404 on non-test hosts.
Route::post('/deploy/test-pull', TestDeployWebhookController::class)
    ->middleware('throttle:10,1');
