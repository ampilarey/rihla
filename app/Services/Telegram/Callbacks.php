<?php

namespace App\Services\Telegram;

use App\Models\Booking;
use App\Models\DepartureChecklistItem;
use App\Models\Payment;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Payments\Ledger;

/**
 * Button taps. Every action is two taps: the first shows exactly what is
 * about to happen, the second does it, as the linked person, through the
 * same services and permissions as the panel. A refusal needs a reason, so
 * it asks and waits for the next message.
 */
final class Callbacks
{
    public function __construct(private readonly Bot $bot, private readonly Ledger $ledger) {}

    /** @param  array<string, mixed>  $callback  the callback_query object */
    public function handle(string $data, TelegramLink $link, array $callback): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? $link->chat_id);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $original = (string) ($callback['message']['text'] ?? '');
        [$verb, $id] = array_pad(explode(':', $data, 2), 2, '');
        $user = $link->user;

        $toast = match ($verb) {
            'cancel' => $this->cancel($chatId, $messageId, $original),
            'pay.reconcile' => $this->payReconcile($user, (int) $id, $chatId, $messageId),
            'pay.reconcile.yes' => $this->payReconcileYes($user, (int) $id, $chatId, $messageId),
            'pay.refuse' => $this->payRefuse($user, $link, (int) $id, $chatId, $messageId),
            'check.tick' => $this->checkTick($user, (int) $id, $chatId, $messageId),
            'check.tick.yes' => $this->checkTickYes($user, (int) $id, $chatId, $messageId),
            default => 'That button is from an older version.',
        };

        $this->bot->answerCallbackQuery((string) ($callback['id'] ?? ''), $toast);
    }

    /** The text that arrived while this chat owed an answer. */
    public function resume(TelegramLink $link, string $text): void
    {
        $pending = $link->pending ?? [];
        $link->forceFill(['pending' => null])->save();
        $user = $link->user;

        if (($pending['action'] ?? null) === 'pay.refuse') {
            $payment = Payment::query()->find($pending['id'] ?? 0);

            if (! $payment instanceof Payment || $payment->status !== Payment::AWAITING_REVIEW) {
                $this->bot->sendMessage($link->chat_id, 'That payment is no longer waiting.');

                return;
            }

            if (! $user->can('payment.reconcile')) {
                $this->bot->sendMessage($link->chat_id, 'Your account cannot refuse payments.');

                return;
            }

            $reason = mb_substr(trim($text), 0, 255);

            if ($reason === '') {
                $this->bot->sendMessage($link->chat_id, 'A refusal needs a reason. Tap "Not received" again and reply with it.');

                return;
            }

            $this->ledger->refuse($payment, $reason, $user);
            $this->bot->sendMessage($link->chat_id, '❌ '.e((string) $payment->reference).' marked not received: '.e($reason));

            return;
        }

        $this->bot->sendMessage($link->chat_id, 'Nothing was waiting for that. Send /help for what I can do.');
    }

    private function cancel(int $chatId, int $messageId, string $original): string
    {
        if ($messageId > 0) {
            $this->bot->editMessageText($chatId, $messageId, e($original)."\n\nCancelled.");
        }

        return 'Cancelled';
    }

    // ── Payments ─────────────────────────────────────────────────────────

    private function payReconcile(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('payment.reconcile')) {
            return 'Your account cannot verify payments.';
        }

        $payment = Payment::query()->find($id);

        if (! $payment instanceof Payment) {
            return 'No such payment.';
        }

        if ($payment->status !== Payment::AWAITING_REVIEW) {
            return 'Already '.str_replace('_', ' ', (string) $payment->status).'.';
        }

        $booking = $payment->booking();

        $this->bot->editMessageText($chatId, $messageId, implode("\n", array_filter([
            '<b>Verify '.e((string) $payment->reference).'?</b>',
            e($payment->money()->format()).' · '.e(str_replace('_', ' ', (string) $payment->method)).($payment->payer_reference ? ' · ref '.e((string) $payment->payer_reference) : ''),
            $booking instanceof Booking ? 'For '.e((string) $booking->reference).' · '.e((string) $booking->customer->name) : null,
            'You have seen the money in the account.',
        ])), Bot::keyboard([['pay.reconcile.yes:'.$id => '✅ Yes, verify'], ['cancel:'.$id => 'Cancel']]));

        return '';
    }

    private function payReconcileYes(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('payment.reconcile')) {
            return 'Your account cannot verify payments.';
        }

        $payment = Payment::query()->find($id);

        if (! $payment instanceof Payment || $payment->status !== Payment::AWAITING_REVIEW) {
            return 'That payment is no longer waiting.';
        }

        $this->ledger->reconcile($payment, 'Verified from Telegram', $user);

        $booking = $payment->fresh()?->booking();

        $this->bot->editMessageText($chatId, $messageId, '✅ <b>'.e((string) $payment->reference).' verified</b> by '.e($user->name)
            ."\n".e($payment->money()->format())
            .($booking instanceof Booking ? ' for '.e((string) $booking->reference)."\n".'Booking now: '.e($booking->balance()->minor > 0 ? $booking->balance()->format().' due' : 'paid in full').'.' : ''));

        return 'Verified';
    }

    private function payRefuse(User $user, TelegramLink $link, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('payment.reconcile')) {
            return 'Your account cannot refuse payments.';
        }

        $payment = Payment::query()->find($id);

        if (! $payment instanceof Payment || $payment->status !== Payment::AWAITING_REVIEW) {
            return 'That payment is no longer waiting.';
        }

        $link->forceFill(['pending' => ['action' => 'pay.refuse', 'id' => $id, 'message_id' => $messageId]])->save();
        $this->bot->sendMessage($chatId, 'Why is '.e((string) $payment->reference).' not received? Reply with the reason, in one message.');

        return 'Reply with the reason';
    }

    // ── Checklist ────────────────────────────────────────────────────────

    private function checkTick(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('checklist.tick')) {
            return 'Your account cannot tick the checklist.';
        }

        $item = DepartureChecklistItem::query()->with('departure.package')->find($id);

        if (! $item instanceof DepartureChecklistItem) {
            return 'No such item.';
        }

        if ($item->isDone()) {
            return 'Already done.';
        }

        $this->bot->editMessageText(
            $chatId,
            $messageId,
            '<b>Tick "'.e((string) $item->title).'"</b> on '.e(DailySummary::label($item->departure)).' as done?',
            Bot::keyboard([['check.tick.yes:'.$id => '✅ Yes, done'], ['cancel:'.$id => 'Cancel']]),
        );

        return '';
    }

    private function checkTickYes(User $user, int $id, int $chatId, int $messageId): string
    {
        if (! $user->can('checklist.tick')) {
            return 'Your account cannot tick the checklist.';
        }

        $item = DepartureChecklistItem::query()->with('departure.package')->find($id);

        if (! $item instanceof DepartureChecklistItem || $item->isDone()) {
            return 'That item is no longer open.';
        }

        $item->markDone($user);
        $this->bot->editMessageText($chatId, $messageId, '✅ <b>'.e((string) $item->title).'</b> ticked by '.e($user->name).' on '.e(DailySummary::label($item->departure)).'.');

        return 'Done';
    }
}
