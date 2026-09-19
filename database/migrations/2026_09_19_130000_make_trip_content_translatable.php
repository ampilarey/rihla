<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give a trip one row and two languages, instead of two rows and neither.
 *
 * `trips` carried two half-built translation mechanisms at once. A `locale`
 * column said which language the row was in, so a Dhivehi trip meant a second,
 * duplicate row. Four `*_dv` columns said the row held both languages at once.
 * The admin panel used both: it listed trips filtered by `locale`, and it
 * revealed the `*_dv` fields only when you picked Dhivehi, so the Dhivehi text
 * could only ever be entered on the duplicate row.
 *
 * Neither reached a visitor. `Trip::published()` has never filtered by locale,
 * so every row showed on both sites, and no public view has ever read a `*_dv`
 * column. What the Dhivehi mechanism did do was block the admin: `TripRequest`
 * made all four columns required the moment you chose Dhivehi, for text
 * nothing rendered.
 *
 * After this there is one row per trip, holding `{"en": "...", "dv": "..."}`
 * per field, read through spatie/laravel-translatable — so a Dhivehi visitor
 * gets the Dhivehi text where it exists and the English where it does not,
 * which is what the two mechanisms were each half of.
 *
 * Production has three trips, all `locale = en`, none with any `*_dv` value
 * set, so this converts them and loses nothing.
 */
return new class extends Migration
{
    /**
     * Base column => the Dhivehi column that shadowed it.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'title' => 'title_dv',
        'location' => 'location_dv',
        'summary' => 'summary_dv',
        'details' => 'details_dv',
    ];

    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            foreach (array_keys(self::FIELDS) as $field) {
                $table->json($field.'_i18n')->nullable();
            }
        });

        foreach (DB::table('trips')->get() as $row) {
            $values = [];

            foreach (self::FIELDS as $base => $dhivehi) {
                $values[$base.'_i18n'] = json_encode(
                    $this->translationsFor($row, $base, $dhivehi),
                    JSON_UNESCAPED_UNICODE,
                );
            }

            DB::table('trips')->where('id', $row->id)->update($values);
        }

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(array_merge(
                ['locale'],
                array_keys(self::FIELDS),
                array_values(self::FIELDS),
            ));
        });

        Schema::table('trips', function (Blueprint $table) {
            foreach (array_keys(self::FIELDS) as $field) {
                $table->renameColumn($field.'_i18n', $field);
            }
        });
    }

    /**
     * Read one field off the old two-mechanism shape.
     *
     * The form showed the Dhivehi inputs *beside* the English ones rather than
     * instead of them, so a row with both filled means both. A row tagged
     * Dhivehi with nothing in its `*_dv` column has only the base column, and
     * on that row the base column is the Dhivehi text.
     *
     * @return array<string, string>
     */
    private function translationsFor(object $row, string $base, string $dhivehi): array
    {
        $baseValue = $row->{$base} ?? null;
        $dhivehiValue = $row->{$dhivehi} ?? null;

        $translations = filled($dhivehiValue)
            ? ['en' => $baseValue, 'dv' => $dhivehiValue]
            : (($row->locale ?? 'en') === 'dv' ? ['dv' => $baseValue] : ['en' => $baseValue]);

        return array_filter($translations, fn ($value) => filled($value));
    }

    /**
     * Split the two languages back into the columns they came from.
     *
     * Reversible, but not lossless in one case the old shape could not hold:
     * a trip translated into Dhivehi came back as a single row with both
     * languages, never as the duplicate row the `locale` column implied.
     */
    public function down(): void
    {
        $rows = DB::table('trips')->get();

        Schema::table('trips', function (Blueprint $table) {
            foreach (array_keys(self::FIELDS) as $field) {
                $table->renameColumn($field, $field.'_i18n');
            }
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->string('locale', 2)->default('en');
            $table->string('title')->nullable();
            $table->string('location')->nullable();
            $table->text('summary')->nullable();
            $table->text('details')->nullable();
            $table->string('title_dv')->nullable();
            $table->string('location_dv')->nullable();
            $table->text('summary_dv')->nullable();
            $table->text('details_dv')->nullable();
        });

        foreach ($rows as $row) {
            $values = [];

            foreach (self::FIELDS as $base => $dhivehi) {
                $translations = json_decode($row->{$base} ?? '{}', true) ?: [];
                $values[$base] = $translations['en'] ?? $translations['dv'] ?? null;
                $values[$dhivehi] = isset($translations['en']) ? ($translations['dv'] ?? null) : null;
            }

            // A trip with no English title is a Dhivehi row in the old shape.
            $title = json_decode($row->title ?? '{}', true) ?: [];
            $values['locale'] = isset($title['en']) ? 'en' : (isset($title['dv']) ? 'dv' : 'en');

            DB::table('trips')->where('id', $row->id)->update($values);
        }

        Schema::table('trips', function (Blueprint $table) {
            foreach (array_keys(self::FIELDS) as $field) {
                $table->dropColumn($field.'_i18n');
            }
        });
    }
};
