<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The homepage blocks follow `trips` and `guide_steps`: one row, both
 * languages.
 *
 * `hero_banners` and `why_sections` each carried a `locale` column with one
 * row per language, and `why_features` — the three cards under "Why Choose
 * Rihla" — had no translation mechanism at all. A Dhivehi why-section meant a
 * second section row with its own three feature rows, related to the English
 * three by nothing, so changing a feature's icon or its order meant doing it
 * twice and hoping.
 *
 * Every environment has one English why-section with three features and no
 * hero banners at all; the Dhivehi rows were deleted earlier as
 * machine-generated. The merge below is written for the general case anyway.
 *
 * URLs are deliberately not translated. A CTA points at the same page in
 * either language, and the locale is a path prefix the router adds — see
 * docs/adr/0001-how-content-is-translated.md.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const TRANSLATABLE = [
        'hero_banners' => ['title', 'subtitle', 'primary_cta_text', 'secondary_cta_text'],
        'why_sections' => ['title', 'subtitle', 'primary_cta_text', 'secondary_cta_text'],
        'why_features' => ['title', 'text', 'link_text'],
    ];

    public function up(): void
    {
        foreach (self::TRANSLATABLE as $table => $fields) {
            Schema::table($table, function (Blueprint $table) use ($fields) {
                foreach ($fields as $field) {
                    $table->json($field.'_i18n')->nullable();
                }
            });
        }

        $this->mergeHeroBanners();
        $this->mergeWhySections();

        // Four indexes lead with `locale`; none of them can survive it.
        Schema::table('hero_banners', function (Blueprint $table) {
            $table->dropIndex('hero_banners_locale_index');
            $table->dropIndex('hero_banners_locale_is_active_index');
            $table->dropIndex('hero_banners_locale_is_active_sort_order_index');
            $table->dropIndex('hero_banners_locale_is_active_start_at_end_at_index');
        });

        foreach (self::TRANSLATABLE as $table => $fields) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $fields) {
                $blueprint->dropColumn(
                    Schema::hasColumn($table, 'locale') ? array_merge(['locale'], $fields) : $fields,
                );
            });

            Schema::table($table, function (Blueprint $blueprint) use ($fields) {
                foreach ($fields as $field) {
                    $blueprint->renameColumn($field.'_i18n', $field);
                }
            });
        }
    }

    /**
     * Banners in the same slot are the same banner. `sort_order` is the only
     * thing the two language rows ever had in common.
     */
    private function mergeHeroBanners(): void
    {
        foreach (DB::table('hero_banners')->orderBy('id')->get()->groupBy('sort_order') as $rows) {
            $primary = $rows->firstWhere('locale', 'en') ?? $rows->first();

            if ($primary === null) {
                continue;
            }

            DB::table('hero_banners')->where('id', $primary->id)->update(
                $this->translationsFrom($rows, self::TRANSLATABLE['hero_banners']),
            );

            $this->deleteMerged('hero_banners', $rows, $primary);
        }
    }

    /**
     * There is one why-section per language, and the homepage shows one. So
     * they all become one, and their features pair off by position.
     */
    private function mergeWhySections(): void
    {
        $sections = DB::table('why_sections')->orderBy('id')->get();

        if ($sections->isEmpty()) {
            return;
        }

        $primary = $sections->firstWhere('locale', 'en') ?? $sections->first();

        if ($primary === null) {
            return;
        }

        DB::table('why_sections')->where('id', $primary->id)->update(
            $this->translationsFrom($sections, self::TRANSLATABLE['why_sections']),
        );

        $primaryFeatures = DB::table('why_features')
            ->where('why_section_id', $primary->id)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('sort_order');

        foreach ($primaryFeatures as $feature) {
            $values = $this->translationsFrom(
                collect([(object) array_merge((array) $feature, ['locale' => $primary->locale ?? 'en'])]),
                self::TRANSLATABLE['why_features'],
            );

            DB::table('why_features')->where('id', $feature->id)->update($values);

            // The loop below merges each other language into these same rows
            // and reads what is already there. Without this it would read the
            // stale copy, which has no translations yet, and the English would
            // be dropped on the floor.
            foreach ($values as $column => $value) {
                $feature->{$column} = $value;
            }
        }

        foreach ($sections as $section) {
            if ($section->id === $primary->id) {
                continue;
            }

            $locale = $section->locale ?? 'en';

            foreach (DB::table('why_features')->where('why_section_id', $section->id)->orderBy('sort_order')->get() as $feature) {
                $match = $primaryFeatures[$feature->sort_order] ?? null;

                if ($match === null) {
                    // Nothing to fold it into. Moving it to the surviving
                    // section would silently add a fourth card to the
                    // homepage, so it moves across switched off, where an
                    // editor can see it and decide.
                    DB::table('why_features')->where('id', $feature->id)->update([
                        'why_section_id' => $primary->id,
                        'is_active' => false,
                    ]);

                    continue;
                }

                $merged = [];

                foreach (self::TRANSLATABLE['why_features'] as $field) {
                    $translations = json_decode($match->{$field.'_i18n'} ?? '{}', true) ?: [];

                    if (filled($feature->{$field})) {
                        $translations[$locale] = $feature->{$field};
                    }

                    $merged[$field.'_i18n'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
                }

                DB::table('why_features')->where('id', $match->id)->update($merged);
                DB::table('why_features')->where('id', $feature->id)->delete();

                // Keep the running copy in step for the next locale.
                foreach ($merged as $column => $value) {
                    $match->{$column} = $value;
                }
            }
        }

        $this->deleteMerged('why_sections', $sections, $primary);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function translationsFrom($rows, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $translations = [];

            foreach ($rows as $row) {
                if (filled($row->{$field} ?? null)) {
                    $translations[$row->locale ?? 'en'] = $row->{$field};
                }
            }

            $values[$field.'_i18n'] = json_encode($translations, JSON_UNESCAPED_UNICODE);
        }

        return $values;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    private function deleteMerged(string $table, $rows, stdClass $primary): void
    {
        $ids = $rows->pluck('id')->reject(fn ($id) => $id === $primary->id);

        if ($ids->isNotEmpty()) {
            DB::table($table)->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Reversible in shape, not in rows.
     *
     * The languages go back into their own columns, and a second row is
     * written for each extra language. The ids of the rows merged on the way
     * up are gone, which is why the merge keeps the English row's id.
     */
    public function down(): void
    {
        $snapshots = [];

        foreach (self::TRANSLATABLE as $table => $fields) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get();

            Schema::table($table, function (Blueprint $blueprint) use ($fields) {
                foreach ($fields as $field) {
                    $blueprint->renameColumn($field, $field.'_i18n');
                }
            });
        }

        Schema::table('hero_banners', function (Blueprint $table) {
            $table->string('locale', 2)->default('en');
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->string('primary_cta_text')->nullable();
            $table->string('secondary_cta_text')->nullable();
            $table->index('locale');
            $table->index(['locale', 'is_active']);
            $table->index(['locale', 'is_active', 'sort_order']);
            $table->index(['locale', 'is_active', 'start_at', 'end_at']);
        });

        Schema::table('why_sections', function (Blueprint $table) {
            $table->string('locale', 2)->default('en');
            $table->string('title')->nullable();
            $table->text('subtitle')->nullable();
            $table->string('primary_cta_text')->nullable();
            $table->string('secondary_cta_text')->nullable();
        });

        Schema::table('why_features', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->text('text')->nullable();
            $table->string('link_text')->nullable();
        });

        foreach ($snapshots as $table => $rows) {
            foreach ($rows as $row) {
                $english = [];

                foreach (self::TRANSLATABLE[$table] as $field) {
                    $translations = json_decode($row->{$field} ?? '{}', true) ?: [];
                    $english[$field] = $translations['en'] ?? reset($translations) ?: null;
                }

                DB::table($table)->where('id', $row->id)->update($english);
            }
        }

        foreach (self::TRANSLATABLE as $table => $fields) {
            Schema::table($table, function (Blueprint $blueprint) use ($fields) {
                foreach ($fields as $field) {
                    $blueprint->dropColumn($field.'_i18n');
                }
            });
        }
    }
};
