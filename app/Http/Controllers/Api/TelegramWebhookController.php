<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\TelegramUpdate;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Router;
use App\Support\CompanyContext;
use App\Support\CompanyRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram calls this once per message. It answers 200 whatever happens,
 * because Telegram retries anything else for hours; what went wrong is
 * written on the update row instead. 404 without a bot token, 403 without
 * the secret Telegram was given when the webhook was registered.
 */
class TelegramWebhookController extends Controller
{
    /**
     * A company's own bot (companies plan, Phase 2d). Everything after this
     * works for that company: its links, its staff's roles there, its
     * records, its bot's token for every reply. A slug that is not an Umrah
     * company with a bot answers exactly as no bot at all does.
     */
    public function forCompany(Request $request, Router $router, string $company): JsonResponse
    {
        $partner = Partner::where('slug', $company)->where('is_umrah_company', true)->where('is_rihla', false)->first();

        abort_if($partner === null || blank($partner->telegram_bot_token), 404);

        app(CompanyContext::class)->use((int) $partner->getKey());
        $request->attributes->set(CompanyRequest::ATTRIBUTE, $partner);

        return $this->__invoke($request, $router);
    }

    public function __invoke(Request $request, Router $router): JsonResponse
    {
        if (! Bot::isConfigured()) {
            abort(404);
        }

        if (! hash_equals(Bot::webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $update = $request->json()->all();
        $updateId = $update['update_id'] ?? null;

        if (! is_int($updateId)) {
            return response()->json(['ok' => true, 'ignored' => 'no update_id']);
        }

        try {
            $record = TelegramUpdate::query()->create(['update_id' => $updateId, 'received_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $router->handle($update, $record);
        } catch (Throwable $e) {
            Log::warning('Telegram update failed', ['update_id' => $updateId, 'error' => $e->getMessage()]);
            $record->forceFill(['error' => mb_substr($e->getMessage(), 0, 255), 'handled_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }
}
