<?php

namespace App\Services\Telegram;

use App\Support\CompanyDetails;
use App\Support\CompanyFeatures;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Bot API, called directly — no bot framework package. Each method is
 * one request; none retries, because a message sent twice is worse than
 * one sent late.
 */
class Bot
{
    /**
     * The token of the bot that speaks for the current company: Rihla's
     * from configuration, or another company's own (companies plan, Phase
     * 2d). A company with no bot has none; it never borrows Rihla's, which
     * would message its staff from somebody else's name.
     */
    public static function token(): ?string
    {
        $company = CompanyDetails::company();

        // A company's bot is a paid feature (Phase 5a): switched off, it is
        // simply not configured, so nothing sends and the webhook answers
        // nobody.
        if ($company !== null && ! CompanyFeatures::has($company, CompanyFeatures::TELEGRAM)) {
            return null;
        }

        $token = $company === null ? config('telegram.bot_token') : $company->telegram_bot_token;

        return filled($token) ? (string) $token : null;
    }

    public static function isConfigured(): bool
    {
        return self::token() !== null;
    }

    /**
     * The secret Telegram echoes back on every webhook call. Each company's
     * bot has its own, derived from the company, so one company's secret
     * opens no other company's webhook.
     */
    public static function webhookSecret(): string
    {
        $company = CompanyDetails::company();

        if ($company !== null) {
            return substr(hash('sha256', 'telegram-webhook|company|'.$company->getKey().'|'.config('app.key')), 0, 48);
        }

        $configured = config('telegram.webhook_secret');

        if (filled($configured)) {
            return (string) $configured;
        }

        return substr(hash('sha256', 'telegram-webhook|'.config('app.key')), 0, 48);
    }

    /** @param  array<string, mixed>  $extra */
    public function sendMessage(int $chatId, string $text, array $extra = []): Response
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    /** Answer a button tap so the phone stops spinning; the text, if any, shows as a toast. */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): Response
    {
        return $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackQueryId, 'text' => $text]));
    }

    /**
     * Replace a message's text (and drop its buttons) once the thing it offered has been done.
     *
     * @param  array<string, mixed>  $extra
     */
    public function editMessageText(int $chatId, int $messageId, string $text, array $extra = []): Response
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    /**
     * An inline keyboard: rows of [callback data => label].
     *
     * @param  list<array<string, string>>  $rows
     * @return array{reply_markup: array<string, mixed>}
     */
    public static function keyboard(array $rows): array
    {
        return ['reply_markup' => ['inline_keyboard' => array_map(
            fn (array $row): array => array_map(
                fn (string $label, string $data): array => ['text' => $label, 'callback_data' => $data],
                array_values($row),
                array_keys($row),
            ),
            $rows,
        )]];
    }

    public function sendDocument(int $chatId, string $filename, string $contents, ?string $caption = null): Response
    {
        return Http::timeout(30)
            ->attach('document', $contents, $filename)
            ->post($this->url('sendDocument'), array_filter(['chat_id' => $chatId, 'caption' => $caption]))
            ->throw();
    }

    public function setWebhook(string $url): Response
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => self::webhookSecret(),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): Response
    {
        return $this->call('deleteWebhook', []);
    }

    public function getWebhookInfo(): Response
    {
        return $this->call('getWebhookInfo', []);
    }

    /** @return array{file_path?: string, file_size?: int} */
    public function getFile(string $fileId): array
    {
        return (array) $this->call('getFile', ['file_id' => $fileId])->json('result', []);
    }

    /** The bytes of a file Telegram holds, by the path getFile returned. */
    public function download(string $filePath): string
    {
        return Http::timeout(60)
            ->get(rtrim((string) config('telegram.api_base'), '/').'/file/bot'.self::token().'/'.$filePath)
            ->throw()
            ->body();
    }

    public function getMe(): Response
    {
        return $this->call('getMe', []);
    }

    /** The bot's username, from config or from Telegram, cached for a day. */
    public function username(): ?string
    {
        $company = CompanyDetails::company();
        $configured = $company === null ? config('telegram.bot_username') : null;

        if (filled($configured)) {
            return ltrim((string) $configured, '@');
        }

        if (! self::isConfigured()) {
            return null;
        }

        // Cached per bot, so one company's name is never shown for another.
        $key = 'telegram.bot_username'.($company === null ? '' : '.'.$company->getKey().'.'.substr(hash('sha256', (string) self::token()), 0, 12));

        return cache()->remember($key, now()->addDay(), function (): ?string {
            $username = $this->getMe()->json('result.username');

            return is_string($username) ? $username : null;
        });
    }

    /** @param  array<string, mixed>  $params */
    private function call(string $method, array $params): Response
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('No Telegram bot token is set for this company.');
        }

        return Http::timeout(15)->asJson()->post($this->url($method), $params)->throw();
    }

    private function url(string $method): string
    {
        return rtrim((string) config('telegram.api_base'), '/').'/bot'.self::token().'/'.$method;
    }
}
