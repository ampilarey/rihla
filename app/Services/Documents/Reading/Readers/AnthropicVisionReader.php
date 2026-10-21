<?php

namespace App\Services\Documents\Reading\Readers;

use App\Models\Document;
use App\Services\Documents\Reading\Extraction;
use App\Services\Documents\Reading\FieldParser;
use App\Services\Documents\Reading\Mrz;
use Illuminate\Support\Facades\Http;

/**
 * Claude's vision API, which reads a phone photo of an ID card far better
 * than Tesseract. Used when a key is set — the drafting assistant's will
 * do — and the image then leaves the server for the one request; nothing
 * is kept on the other side. The MRZ it reads back is still check-digited
 * here, because a model can misread a digit as well as a camera can.
 */
final class AnthropicVisionReader implements Reader
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return filled(config('documents.anthropic.key'));
    }

    public function read(string $bytes, string $mime, string $type): Extraction
    {
        $x = new Extraction($this->name(), model: (string) config('documents.anthropic.model'));

        $content = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];

        $response = Http::withHeaders([
            'x-api-key' => (string) config('documents.anthropic.key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout((int) config('documents.anthropic.timeout', 45))
            ->post(rtrim((string) config('documents.anthropic.base_url'), '/').'/v1/messages', [
                'model' => $x->model,
                'max_tokens' => 800,
                'system' => 'You read scanned identity and travel documents for a Maldivian travel agency and answer only with a JSON object. Never guess: omit a field you cannot read. Dates as YYYY-MM-DD. Names in upper case as printed.',
                'messages' => [[
                    'role' => 'user',
                    'content' => [$content, ['type' => 'text', 'text' => self::prompt($type)]],
                ]],
            ])
            ->throw();

        $text = (string) (collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? '');
        $x->rawText = $text;

        if (preg_match('/\{.*\}/s', $text, $m) === 1 && is_array($json = json_decode($m[0], true))) {
            foreach ($json as $field => $value) {
                if ($field === 'confidence' || $field === 'mrz') {
                    continue;
                }

                $x->set((string) $field, is_scalar($value) ? (string) $value : null, (float) ($json['confidence'][$field] ?? 0.8));
            }

            if (is_string($json['mrz'] ?? null)) {
                $mrz = Mrz::parse($json['mrz']);
                $x->mrzValid = $mrz['valid'] ?? null;
                $x->rawText .= "\n".$json['mrz'];

                // What the check digits confirm beats what the model read off the visual zone.
                if ($mrz !== null && $mrz['valid']) {
                    $x->set('passport_number', $mrz['passport_number'], 0.97);
                    $x->set('date_of_birth', $mrz['date_of_birth'], 0.97);
                    $x->set('passport_expiry', $mrz['expires_on'], 0.97);
                }
            }
        } else {
            FieldParser::apply($x, $type);
        }

        return $x;
    }

    private static function prompt(string $type): string
    {
        return match ($type) {
            Document::NATIONAL_ID => 'This is a Maldives national ID card. Return JSON with: full_name, national_id (format A######), gender (male|female), date_of_birth, id_expiry, address, and confidence: {field: 0-1}.',
            Document::PASSPORT => 'This is a passport. Return JSON with: surname, given_names, full_name, passport_number, nationality (3 letters), gender (male|female), date_of_birth, passport_expiry, passport_issuing_country (3 letters), mrz (the two machine-readable lines exactly, separated by a newline), and confidence: {field: 0-1}.',
            Document::VISA => 'This is a visa or e-visa. Return JSON with: number, full_name, passport_number, issued_on, expires_on, and confidence: {field: 0-1}.',
            Document::BANK_SLIP => 'This is a bank transfer slip or receipt. Return JSON with: amount (number), currency (MVR|USD|SAR), reference, paid_on, payer_account, payee_account, remarks, and confidence: {field: 0-1}.',
            default => 'Return JSON with every labelled field you can read, as field: value, and confidence: {field: 0-1}.',
        };
    }
}
