<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A trip is one record in two languages.
 *
 * `trips` used to carry two half-built translation mechanisms at once: a
 * `locale` column, which made a Dhivehi trip a second duplicate row, and four
 * `*_dv` columns, which made it the same row twice over. Neither reached a
 * visitor. `Trip::published()` has never filtered by locale, so every row
 * showed on both sites, and no public view has ever read a `*_dv` column.
 *
 * What the Dhivehi half did do was stop an editor: TripRequest made all four
 * `*_dv` columns required the moment the language was set to Dhivehi, for text
 * that nothing rendered.
 *
 * Nothing asserted any of it, which is why it survived two years and a
 * production launch.
 */
class TripTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /** @param array<string, mixed> $overrides */
    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => ['en' => 'Seven Nights in Madinah', 'dv' => 'މަދީނާގައި ހަތް ރޭ'],
            'slug' => 'seven-nights-madinah',
            'date_start' => now()->subDay(),
            'date_end' => now()->addDays(6),
            'status' => Trip::STATUS_CURRENT,
            'is_published' => true,
        ], $overrides));
    }

    public function test_the_two_old_mechanisms_are_gone_from_the_table(): void
    {
        $columns = Schema::getColumnListing('trips');

        foreach (['locale', 'title_dv', 'location_dv', 'summary_dv', 'details_dv'] as $column) {
            $this->assertNotContains($column, $columns, "trips still has {$column}.");
        }
    }

    /**
     * The conversion itself, against the shape it had to read.
     *
     * Production had three trips when this ran. A migration that quietly
     * dropped a column's contents would have looked exactly like a migration
     * that worked, because nothing rendered those columns either way.
     */
    public function test_the_migration_carries_the_old_shape_across(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $base = [
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-10',
            'status' => 'upcoming',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'location' => null,
            'location_dv' => null,
            'summary' => null,
            'summary_dv' => null,
            'details' => null,
            'details_dv' => null,
            'title_dv' => null,
        ];

        DB::table('trips')->insert([
            // The shape the form actually produced: English base columns with
            // the Dhivehi ones filled in beside them.
            array_merge($base, [
                'slug' => 'both', 'locale' => 'dv',
                'title' => 'Seven Nights in Madinah', 'title_dv' => 'މަދީނާގައި ހަތް ރޭ',
                'summary' => 'Seven nights.', 'summary_dv' => 'ހަތް ރޭ.',
            ]),
            // A row tagged Dhivehi with nothing in its Dhivehi columns: the
            // base column is the only text it has, and it is Dhivehi.
            array_merge($base, [
                'slug' => 'dhivehi-only', 'locale' => 'dv', 'title' => 'ޢުމްރާ',
            ]),
            // What production actually held: English, untranslated.
            array_merge($base, [
                'slug' => 'english-only', 'locale' => 'en', 'title' => 'Shawwal Umrah',
            ]),
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $both = Trip::where('slug', 'both')->sole();
        $this->assertSame('Seven Nights in Madinah', $both->getTranslation('title', 'en'));
        $this->assertSame('މަދީނާގައި ހަތް ރޭ', $both->getTranslation('title', 'dv'));
        $this->assertSame('ހަތް ރޭ.', $both->getTranslation('summary', 'dv'));

        $dhivehi = Trip::where('slug', 'dhivehi-only')->sole();
        $this->assertSame('ޢުމްރާ', $dhivehi->getTranslation('title', 'dv'));
        $this->assertFalse($dhivehi->hasTranslation('title', 'en'));

        $english = Trip::where('slug', 'english-only')->sole();
        $this->assertSame('Shawwal Umrah', $english->getTranslation('title', 'en'));
        $this->assertFalse($english->hasTranslation('title', 'dv'));

        // A field that was empty on both sides is empty, not the string "null".
        $this->assertSame([], $english->getTranslations('location'));
    }

    public function test_one_row_carries_both_languages(): void
    {
        $trip = $this->trip();

        $this->assertSame(1, Trip::count(), 'A translated trip is still one trip.');
        $this->assertSame('Seven Nights in Madinah', $trip->getTranslation('title', 'en'));
        $this->assertSame('މަދީނާގައި ހަތް ރޭ', $trip->getTranslation('title', 'dv'));
    }

    public function test_a_dhivehi_visitor_reads_the_dhivehi_title(): void
    {
        $this->trip();

        $this->get('/dv/trips')->assertOk()
            ->assertSee('މަދީނާގައި ހަތް ރޭ', false)
            ->assertDontSee('Seven Nights in Madinah');

        $this->get('/en/trips')->assertOk()
            ->assertSee('Seven Nights in Madinah')
            ->assertDontSee('މަދީނާގައި ހަތް ރޭ', false);
    }

    /**
     * Dhivehi is written slowly and by a person. Until it exists, the English
     * shows — which is the whole reason the fallback is configured.
     */
    public function test_an_untranslated_trip_falls_back_to_english(): void
    {
        $this->trip(['title' => ['en' => 'Shawwal Umrah']]);

        $this->get('/dv/trips')->assertOk()->assertSee('Shawwal Umrah');
    }

    /**
     * The old shape listed a Dhivehi trip as its own row with its own slug,
     * and the public query filtered by neither — so both showed, in both
     * languages, as two separate trips.
     */
    public function test_a_translated_trip_is_listed_once_in_each_language(): void
    {
        $this->trip();

        foreach (['en', 'dv'] as $locale) {
            $response = $this->get("/{$locale}/trips");

            $this->assertSame(
                1,
                substr_count($response->getContent(), '/trips/seven-nights-madinah'),
                "The trip is linked more than once on the {$locale} list.",
            );
        }
    }

    public function test_the_admin_form_saves_both_languages_in_one_submit(): void
    {
        $this->actingAs($this->admin())->post(route('admin.trips.store'), [
            'title' => ['en' => 'Ramadan Umrah 2026', 'dv' => 'ރަމަޟާން އުމްރާ ٢٠٢٦'],
            'summary' => ['en' => 'Fourteen nights.', 'dv' => ''],
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-14',
            'status' => 'upcoming',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $trip = Trip::sole();

        $this->assertSame('Ramadan Umrah 2026', $trip->getTranslation('title', 'en'));
        $this->assertSame('ރަމަޟާން އުމްރާ ٢٠٢٦', $trip->getTranslation('title', 'dv'));

        // An empty box is not a translation. Storing '' would make the trip
        // look translated and render a blank summary to Dhivehi readers.
        $this->assertFalse($trip->hasTranslation('summary', 'dv'));

        // The slug is ASCII, so it comes from the English title whatever the
        // Dhivehi says.
        $this->assertSame('ramadan-umrah-2026', $trip->slug);
    }

    /**
     * The old request made every Dhivehi field required as soon as the trip
     * was Dhivehi. A staff member with no Thaana keyboard could not save.
     */
    public function test_dhivehi_is_never_required(): void
    {
        $this->actingAs($this->admin())->post(route('admin.trips.store'), [
            'title' => ['en' => 'Shawwal Umrah', 'dv' => ''],
            'location' => ['en' => 'Makkah & Madinah', 'dv' => ''],
            'date_start' => '2026-05-01',
            'date_end' => '2026-05-10',
            'status' => 'upcoming',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Trip::count());
    }

    /** English is the fallback, so a trip without it would be blank for everyone else. */
    public function test_english_is_required(): void
    {
        $this->actingAs($this->admin())->post(route('admin.trips.store'), [
            'title' => ['en' => '', 'dv' => 'ރަމަޟާން އުމްރާ'],
            'date_start' => '2026-05-01',
            'date_end' => '2026-05-10',
            'status' => 'upcoming',
        ])->assertSessionHasErrors('title.en');

        $this->assertSame(0, Trip::count());
    }

    /**
     * The panel listed trips filtered by the *panel's own* locale, so a
     * Dhivehi trip was invisible to an editor reading the panel in English —
     * while being perfectly visible to every visitor.
     */
    public function test_the_panel_lists_every_trip_whatever_language_it_is_in(): void
    {
        $this->trip();
        $this->trip(['title' => ['dv' => 'ޢުމްރާ'], 'slug' => 'umrah-dv']);

        $response = $this->actingAs($this->admin())->get(route('admin.trips.index'));

        $response->assertOk()
            ->assertSee('seven-nights-madinah')
            ->assertSee('umrah-dv');
    }

    /**
     * A bare string means English, because that is what every caller written
     * before this change meant by it.
     */
    public function test_a_plain_string_is_taken_as_english(): void
    {
        $this->actingAs($this->admin())->post(route('admin.trips.store'), [
            'title' => 'Rabi al-Awwal Umrah',
            'date_start' => '2026-09-01',
            'date_end' => '2026-09-10',
            'status' => 'upcoming',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Rabi al-Awwal Umrah', Trip::sole()->getTranslation('title', 'en'));
    }
}
