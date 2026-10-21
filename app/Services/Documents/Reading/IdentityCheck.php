<?php

namespace App\Services\Documents\Reading;

use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Traveller;

/**
 * Does the ID card agree with the passport? Compares what each accepted
 * reading said: date of birth and gender must match; names are compared
 * as sets of words, so "MOHAMED AHMED" and "AHMED, MOHAMED" agree. A
 * mismatch is recorded on the traveller and shown everywhere until a
 * person explains it — a name change is common and not a problem, but
 * a different date of birth is a visa refusal waiting to happen.
 */
class IdentityCheck
{
    public const NAME_THRESHOLD = 0.6;

    /** @return list<array<string, mixed>> */
    public function run(Traveller $traveller): array
    {
        $card = self::accepted($traveller, Document::NATIONAL_ID);
        $passport = self::accepted($traveller, Document::PASSPORT);

        if ($card === null || $passport === null) {
            return $traveller->identity_mismatches ?? [];
        }

        $found = [];

        if (isset($card['date_of_birth'], $passport['date_of_birth']) && $card['date_of_birth'] !== $passport['date_of_birth']) {
            $found[] = ['field' => 'date_of_birth', 'id_card' => $card['date_of_birth'], 'passport' => $passport['date_of_birth']];
        }

        if (isset($card['gender'], $passport['gender']) && strtolower((string) $card['gender']) !== strtolower((string) $passport['gender'])) {
            $found[] = ['field' => 'gender', 'id_card' => $card['gender'], 'passport' => $passport['gender']];
        }

        $cardName = $card['full_name'] ?? null;
        $passportName = $passport['full_name'] ?? trim(($passport['given_names'] ?? '').' '.($passport['surname'] ?? ''));

        if (filled($cardName) && filled($passportName) && self::nameSimilarity((string) $cardName, $passportName) < self::NAME_THRESHOLD) {
            $found[] = ['field' => 'name', 'id_card' => $cardName, 'passport' => $passportName];
        }

        // Keep explanations staff already gave for the same mismatch.
        $previous = collect($traveller->identity_mismatches ?? []);
        $merged = array_map(function (array $mismatch) use ($previous): array {
            $old = $previous->first(fn (array $p): bool => $p['field'] === $mismatch['field']
                && ($p['id_card'] ?? null) === $mismatch['id_card']
                && ($p['passport'] ?? null) === $mismatch['passport']);

            return $old === null
                ? $mismatch + ['explained' => false, 'note' => null]
                : array_merge($mismatch, ['explained' => (bool) ($old['explained'] ?? false), 'note' => $old['note'] ?? null]);
        }, $found);

        $traveller->forceFill(['identity_mismatches' => $merged === [] ? null : $merged])->save();

        return $merged;
    }

    public function explain(Traveller $traveller, string $field, string $note): void
    {
        $mismatches = array_map(function (array $mismatch) use ($field, $note): array {
            if ($mismatch['field'] === $field) {
                $mismatch['explained'] = true;
                $mismatch['note'] = $note;
            }

            return $mismatch;
        }, $traveller->identity_mismatches ?? []);

        $traveller->forceFill(['identity_mismatches' => $mismatches])->save();
    }

    /** Jaccard similarity of the words, after upper-casing and dropping punctuation. */
    public static function nameSimilarity(string $a, string $b): float
    {
        $words = fn (string $s): array => array_values(array_unique(array_filter(
            preg_split('/\s+/', strtoupper((string) preg_replace('/[^A-Za-z\s]/', ' ', $s))) ?: [],
        )));
        $wordsA = $words($a);
        $wordsB = $words($b);

        if ($wordsA === [] || $wordsB === []) {
            return 0.0;
        }

        $union = array_unique(array_merge($wordsA, $wordsB));

        return count(array_intersect($wordsA, $wordsB)) / count($union);
    }

    /** @return array<string, mixed>|null */
    private static function accepted(Traveller $traveller, string $type): ?array
    {
        $document = $traveller->documents()->where('type', $type)->first();

        if ($document === null) {
            return null;
        }

        return DocumentExtraction::query()
            ->whereIn('document_version_id', $document->versions()->select('id'))
            ->where('status', DocumentExtraction::ACCEPTED)
            ->latest('id')
            ->first()
            ?->accepted_fields;
    }
}
