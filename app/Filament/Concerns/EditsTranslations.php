<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Lets a Filament form edit a spatie-translatable column in both languages,
 * in both directions.
 *
 * **Filling.** `$package->title` is the translation for the *current* locale
 * — a string. A form bound to it edits one language and silently discards the
 * other. What a form needs is the whole `{"en": …, "dv": …}` array, which is
 * what getTranslations() returns and what assigning an array back sets. So
 * the translatable keys are expanded before the form fills, and the fields
 * are named `title.en` / `title.dv`.
 *
 * **Saving.** The form always submits every locale tab, so a package written
 * only in English arrives carrying `dv => ''` or `dv => []`. Stored, that is
 * worse than nothing: hasTranslation() answers *true* for an empty value, so
 * the per-field fallback never fires and the Dhivehi page renders a blank
 * title or an empty inclusions list where it should show the English. An
 * empty box is not a translation — the same rule the media captions follow.
 *
 * Hand-rolled rather than adding filament/spatie-laravel-translatable-plugin:
 * Filament already brought in 32 packages that have to be upgraded by hand
 * over SSH (ADR 0002, ADR 0003).
 */
trait EditsTranslations
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function expandTranslations(array $data, ?Model $record = null): array
    {
        $record ??= $this->getRecord();

        foreach (self::translatableAttributes($record) as $attribute) {
            $data[$attribute] = $record->getTranslations($attribute);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pruneEmptyTranslations(array $data, ?Model $record = null): array
    {
        $record ??= $this->getRecord();

        return self::withoutEmptyLocales($data, self::translatableAttributes($record));
    }

    /**
     * Drop every locale whose value is blank, for each named attribute.
     *
     * Static and public so a Repeater inside a relation manager can reach it
     * for its own nested translatable rows.
     *
     * @param  array<string, mixed>  $data
     * @param  iterable<int, string>  $attributes
     * @return array<string, mixed>
     */
    public static function withoutEmptyLocales(array $data, iterable $attributes): array
    {
        foreach ($attributes as $attribute) {
            if (! is_array($data[$attribute] ?? null)) {
                continue;
            }

            $data[$attribute] = array_filter(
                $data[$attribute],
                static fn ($value): bool => filled($value),
            );
        }

        return $data;
    }

    /** @return array<int, string> */
    private static function translatableAttributes(?Model $record): array
    {
        if (! $record || ! in_array(HasTranslations::class, class_uses_recursive($record), true)) {
            return [];
        }

        /** @var array<int, string> */
        return $record->translatable ?? [];
    }
}
