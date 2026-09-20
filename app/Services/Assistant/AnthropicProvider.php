<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Anthropic Messages API, called directly over HTTP — §9.6.
 *
 * No SDK: one POST with three headers is not worth a dependency on a host
 * where every package is another thing to keep current by hand (ADR 0002).
 *
 * ## It never throws at a pilgrim
 *
 * Every failure — no key, a timeout, a rate limit, a malformed body —
 * returns null, and {@see PilgrimAssistant} turns null into the referral to
 * a human that §9.6 requires. A pilgrim asking a question about their
 * Umrah must never see a stack trace, and must never be left wondering
 * whether the silence was an answer.
 *
 * ## Nothing personal goes in the prompt
 *
 * §9.6 prohibits personal data in prompts. This class is handed a system
 * prompt and a question and knows nothing about who asked; the caller is
 * the one holding that line, and it holds it by never passing the name.
 */
final class AnthropicProvider implements Provider
{
    public function isConfigured(): bool
    {
        return filled(config('assistant.anthropic.key'))
            && filled(config('assistant.anthropic.model'));
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) config('assistant.anthropic.model', 'unknown');
    }

    public function complete(string $system, string $question): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('assistant.anthropic.key'),
                'anthropic-version' => (string) config('assistant.anthropic.version', '2023-06-01'),
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('assistant.anthropic.timeout', 25))
                ->post(rtrim((string) config('assistant.anthropic.base_url'), '/').'/v1/messages', [
                    'model' => $this->model(),
                    'max_tokens' => (int) config('assistant.anthropic.max_tokens', 900),
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $question],
                    ],
                ]);

            if ($response->failed()) {
                // The status and nothing else: the body of a failed call can
                // echo the prompt, and the prompt carries scripture.
                Log::warning('The pilgrim assistant was refused by its provider.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $text = collect((array) $response->json('content'))
                ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text')
                ->map(fn (array $block): string => (string) ($block['text'] ?? ''))
                ->join("\n");

            return trim($text) === '' ? null : trim($text);
        } catch (Throwable $e) {
            Log::warning('The pilgrim assistant could not reach its provider.', [
                'exception' => $e::class,
            ]);

            return null;
        }
    }
}
