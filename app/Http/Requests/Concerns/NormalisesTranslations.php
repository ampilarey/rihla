<?php

namespace App\Http\Requests\Concerns;

/**
 * Shared handling for fields the form sends once per language.
 *
 * A translated field arrives as `title[en]` / `title[dv]`. Everything written
 * before the i18n redesign sends a bare `title=…`, and means English by it —
 * so a bare value is read that way rather than rejected with an error an
 * editor could not act on.
 */
trait NormalisesTranslations
{
    /**
     * Turn bare values into `['en' => …]`, and list fields into arrays.
     *
     * @param  list<string>  $fields  text fields
     * @param  list<string>  $lists  fields whose value is one item per line
     */
    protected function normaliseTranslations(array $fields, array $lists = []): void
    {
        $normalised = [];

        foreach ($fields as $field) {
            if ($this->has($field) && ! is_array($this->input($field))) {
                $normalised[$field] = ['en' => $this->input($field)];
            }
        }

        foreach ($lists as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            // A bare list — `notes[]=…`, or an array posted by a test — is
            // English. Only a map keyed by language is already per-locale.
            if (! is_array($value) || array_is_list($value)) {
                $value = ['en' => $value];
            }

            $normalised[$field] = array_filter(
                array_map($this->toList(...), $value),
                static fn (array $items): bool => $items !== [],
            );
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    /**
     * One item per line, blank lines dropped.
     *
     * An array passes through unchanged so a test or a future API can post one
     * directly.
     *
     * @return list<string>
     */
    protected function toList(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : (is_string($value) ? (preg_split('/\r\n|\r|\n/', $value) ?: []) : []);

        return array_values(array_filter(
            array_map(static fn ($item): string => trim((string) $item), $items),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Rules for a translated text field: English required or not, Dhivehi
     * never required.
     *
     * @return array<string, list<string>>
     */
    protected function translatedRules(string $field, bool $required, ?int $max = null): array
    {
        $length = $max === null ? [] : ['max:'.$max];

        return [
            $field => [$required ? 'required' : 'nullable', 'array'],
            $field.'.en' => array_merge([$required ? 'required' : 'nullable', 'string'], $length),
            $field.'.dv' => array_merge(['nullable', 'string'], $length),
        ];
    }
}
