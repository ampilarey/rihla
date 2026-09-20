<?php

namespace App\Filament\Concerns;

/**
 * Helpers for editing spatie-translatable columns in a Filament form.
 *
 * **Filling.** `$package->title` is the translation for the *current* locale
 * — a string. A form bound to it edits one language and silently discards the
 * other. What a form needs is the whole `{"en": …, "dv": …}` array, which is
 * what getTranslations() returns and what assigning an array back sets. So
 * the pages expand the translatable keys before the form fills, and the
 * fields are named `title.en` / `title.dv`.
 *
 * **Saving.** The form always submits every locale tab, so a package written
 * only in English arrives carrying `dv => ''` or `dv => []`. Stored, that is
 * worse than nothing: hasTranslation() answers *true* for an empty value, so
 * the per-field fallback never fires and the Dhivehi page renders a blank
 * title or an empty inclusions list where it should show the English. An
 * empty box is not a translation — the same rule the media captions follow.
 *
 * Both helpers work on plain arrays and take the attribute list, rather than
 * taking a model and reaching for its trait methods. A model parameter would
 * have to be typed `Model`, which does not have getTranslations() — the first
 * draft did that, guarded it with class_uses_recursive(), and static analysis
 * rightly refused to accept a runtime check as a type.
 *
 * Hand-rolled rather than adding filament/spatie-laravel-translatable-plugin:
 * Filament already brought in 32 packages that have to be upgraded by hand
 * over SSH (ADR 0002, ADR 0003).
 */
trait EditsTranslations
{
    /**
     * Replace each translatable key with its whole `{locale: value}` array.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $translations  attribute => translations
     * @return array<string, mixed>
     */
    public static function withTranslationArrays(array $data, array $translations): array
    {
        return array_replace($data, $translations);
    }

    /**
     * Drop every locale whose value is blank, for each named attribute.
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
}
