<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Payment;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One message to everyone linked who holds a permission.
 *
 * The person who caused the alert is left out: they know. Each send is its
 * own request and a failure is logged, never thrown, because a dead chat
 * must not stop the others hearing — and because this is called from
 * inside a payment transition, where an exception would undo the money.
 */
final class Notifier
{
    public function __construct(private readonly Bot $bot) {}

    /** @param  array<string, mixed>  $extra  e.g. Bot::keyboard([...]) */
    public function tell(string $permission, string $text, array $extra = [], ?int $exceptUserId = null): int
    {
        if (! Bot::isConfigured()) {
            return 0;
        }

        $sent = 0;

        TelegramLink::query()->active()->with('user')->each(function (TelegramLink $link) use ($permission, $text, $extra, $exceptUserId, &$sent): void {
            $user = $link->user;

            if (! $user instanceof User || $user->getKey() === $exceptUserId || ! $user->can($permission)) {
                return;
            }

            try {
                $this->bot->sendMessage($link->chat_id, $text, $extra);
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Telegram alert failed', ['user_id' => $user->getKey(), 'error' => $e->getMessage()]);
            }
        });

        return $sent;
    }

    /** A slip has arrived and the money has to be checked: told to whoever reconciles. */
    public function paymentWaiting(Payment $payment): int
    {
        if (! Bot::isConfigured()) {
            return 0;
        }

        try {
            $booking = $payment->booking();

            $text = implode("\n", array_filter([
                '<b>Payment to verify</b>',
                e((string) $payment->reference).' · '.e($payment->money()->format()).' · '.e(str_replace('_', ' ', (string) $payment->method)),
                $booking instanceof Booking
                    ? 'For '.e((string) $booking->reference).' · '.e((string) $booking->customer->name)
                    : 'For a guesthouse stay',
                $payment->payer_reference ? 'Bank ref '.e((string) $payment->payer_reference) : null,
                $payment->hasSlip() ? 'Slip attached.' : 'No slip attached.',
                '',
                'Panel: '.e(self::panelUrl('payments')),
            ]));

            return $this->tell('payment.reconcile', $text, Bot::keyboard([[
                'pay.reconcile:'.$payment->getKey() => 'Verify',
                'pay.refuse:'.$payment->getKey() => 'Not received',
            ]]), $payment->recorded_by === null ? null : (int) $payment->recorded_by);
        } catch (Throwable $e) {
            Log::warning('Telegram payment alert failed', ['payment_id' => $payment->getKey(), 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /** A scan was read and its fields wait to be accepted: told to whoever verifies documents. */
    public function readingProposed(DocumentExtraction $extraction): int
    {
        if (! Bot::isConfigured()) {
            return 0;
        }

        try {
            $document = $extraction->version->document;
            $fields = $extraction->fields ?? [];

            $text = implode("\n", array_filter([
                '<b>Reading to accept</b>',
                e($document->typeLabel()).' of <b>'.e((string) $document->traveller->full_name).'</b> · read by '.e($extraction->reader),
                count($fields).' field'.(count($fields) === 1 ? '' : 's').' proposed'.($extraction->lowConfidenceFields() !== [] ? ', '.count($extraction->lowConfidenceFields()).' unsure' : '').'.',
                $document->type === Document::PASSPORT
                    ? ($extraction->mrz_valid === true ? 'MRZ check digits pass.' : ($extraction->mrz_valid === false ? '⚠ MRZ check digits FAIL.' : 'No MRZ read.'))
                    : null,
                '',
                'Panel: '.e(self::panelUrl('documents')),
            ]));

            return $this->tell('document.update', $text, [], $extraction->version->uploaded_by === null ? null : (int) $extraction->version->uploaded_by);
        } catch (Throwable $e) {
            Log::warning('Telegram reading alert failed', ['extraction_id' => $extraction->getKey(), 'error' => $e->getMessage()]);

            return 0;
        }
    }

    public static function panelUrl(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/staff'.($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
