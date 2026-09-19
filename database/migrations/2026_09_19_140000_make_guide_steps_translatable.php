<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One guide step, both languages — following `trips`.
 *
 * `guide_steps` kept a `locale` column and one row per language, so step 3 of
 * the Umrah guide was two unrelated records that agreed only by convention on
 * their `step_number`. Nothing joined them: publishing the English step said
 * nothing about the Dhivehi one, reordering had to be done twice, and a
 * missing Dhivehi step meant `PageController` fell back to *the whole English
 * guide*, not to the one step that was missing.
 *
 * The Dhivehi rows were deleted in an earlier migration because they were
 * machine-generated — all ten carried the same du'a where the English ten
 * carry ten real ones. So there is nothing to merge here in practice: every
 * environment has ten English rows and no Dhivehi ones, and each becomes a
 * row holding `{"en": …}`. The merge below is written for the general case
 * anyway, because a migration that only works on the data you happened to
 * look at is not a migration.
 *
 * `dua_text` is deliberately *not* translatable. It is the Arabic of the rite,
 * identical in every language — data, not a translation. See
 * docs/adr/0001-how-content-is-translated.md.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TRANSLATABLE = ['title', 'summary', 'details', 'reference_text', 'fiqh_notes', 'checklist'];

    /**
     * Columns that describe the step rather than say anything in a language.
     * When two locale rows are merged, these come from the English one.
     *
     * @var list<string>
     */
    private const SHARED = ['dua_text', 'video_url', 'image_path', 'is_published'];

    public function up(): void
    {
        Schema::table('guide_steps', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->json($field.'_i18n')->nullable();
            }
        });

        $groups = DB::table('guide_steps')->orderBy('id')->get()->groupBy('step_number');

        foreach ($groups as $rows) {
            // The English row is the one that keeps its id, its image and its
            // publication state; if there is no English row, the first one is.
            $primary = $rows->firstWhere('locale', 'en') ?? $rows->first();

            $values = [];

            foreach (self::TRANSLATABLE as $field) {
                $translations = [];

                foreach ($rows as $row) {
                    $value = $row->{$field} ?? null;

                    if (filled($value)) {
                        $translations[$row->locale] = $this->decodeIfJson($field, $value);
                    }
                }

                $values[$field.'_i18n'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
            }

            foreach (self::SHARED as $field) {
                $values[$field] = $primary->{$field};
            }

            DB::table('guide_steps')->where('id', $primary->id)->update($values);

            $duplicates = $rows->pluck('id')->reject(fn ($id) => $id === $primary->id);

            if ($duplicates->isNotEmpty()) {
                DB::table('guide_steps')->whereIn('id', $duplicates)->delete();
            }
        }

        Schema::table('guide_steps', function (Blueprint $table) {
            $table->dropIndex('guide_steps_locale_is_published_step_number_index');
        });

        Schema::table('guide_steps', function (Blueprint $table) {
            $table->dropColumn(array_merge(['locale'], self::TRANSLATABLE));
        });

        Schema::table('guide_steps', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->renameColumn($field.'_i18n', $field);
            }
        });

        Schema::table('guide_steps', function (Blueprint $table) {
            $table->index(['is_published', 'step_number']);
        });
    }

    /**
     * `checklist` and `fiqh_notes` already hold a JSON array. Nesting the
     * encoded string inside the per-language JSON would store the list as one
     * long string that the `array` cast then hands back as text.
     */
    private function decodeIfJson(string $field, mixed $value): mixed
    {
        if (! in_array($field, ['checklist', 'fiqh_notes'], true) || ! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $value;
    }

    /**
     * Reversible, but it cannot resurrect rows it deleted.
     *
     * Rolling back restores the columns and writes each step's English text
     * back into them, with the Dhivehi in a second row where one exists. The
     * ids of any rows merged on the way up are gone, which is why the merge
     * keeps the English row's id rather than inventing one.
     */
    public function down(): void
    {
        $rows = DB::table('guide_steps')->orderBy('id')->get();

        Schema::table('guide_steps', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'step_number']);
        });

        Schema::table('guide_steps', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->renameColumn($field, $field.'_i18n');
            }
        });

        Schema::table('guide_steps', function (Blueprint $table) {
            $table->string('locale', 2)->default('en');
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->longText('details')->nullable();
            $table->text('reference_text')->nullable();
            $table->text('fiqh_notes')->nullable();
            $table->text('checklist')->nullable();
        });

        foreach ($rows as $row) {
            $byLocale = [];

            foreach (self::TRANSLATABLE as $field) {
                foreach (json_decode($row->{$field} ?? '{}', true) ?: [] as $locale => $value) {
                    $byLocale[$locale][$field] = is_array($value)
                        ? json_encode($value, JSON_UNESCAPED_UNICODE)
                        : $value;
                }
            }

            $byLocale = $byLocale === [] ? ['en' => []] : $byLocale;

            $first = true;

            foreach ($byLocale as $locale => $values) {
                $values['locale'] = $locale;

                if ($first) {
                    DB::table('guide_steps')->where('id', $row->id)->update($values);
                    $first = false;

                    continue;
                }

                // Only the columns this row is meant to carry. Copying the
                // whole record would put the merged JSON into whichever
                // language columns this locale happens not to fill.
                $shared = ['step_number' => $row->step_number];

                foreach (self::SHARED as $field) {
                    $shared[$field] = $row->{$field};
                }

                DB::table('guide_steps')->insert(array_merge($shared, $values, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        Schema::table('guide_steps', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->dropColumn($field.'_i18n');
            }

            $table->index(['locale', 'is_published', 'step_number']);
        });
    }
};
