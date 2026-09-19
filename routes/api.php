<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\TestDeployWebhookController;
use Illuminate\Support\Facades\Route;

// Same URL and same response it has always given. It now also reports the
// running commit to a caller holding the deploy secret, which is how the
// deploy workflow tells a deploy that landed from one that only started.
Route::get('/health', HealthController::class)->middleware('throttle:60,1');

// TEST-only immediate deploy trigger (GitHub Actions → cPanel). Disabled when
// TEST_DEPLOY_WEBHOOK_SECRET is unset; always 404 on non-test hosts.
Route::post('/deploy/test-pull', TestDeployWebhookController::class)
    ->middleware('throttle:10,1');
