<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Setting;
use App\Models\Trip;
use Database\Seeders\MediaSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Demo content has embarrassed this site twice.
 *
 * The first time it reached production and advertised resort holidays on an
 * Umrah site, which is why every demo seeder now refuses to run there. That
 * guard stopped it reaching production and nothing else: the content itself
 * stayed exactly as it was, so test.rihla.mv — public, indexed, under Rihla's
 * branding — went on showing "Maldives Sunset" and "Crystal Clear Waters" to
 * anyone who visited, plus two videos.
 *
 * The videos were the worse half. Both carried a "Replace with actual video"
 * comment, and neither was: a pilgrimage operator's Cultural Heritage Tour
 * was Rick Astley, and its Local Market Experience was Gangnam Style.
 *
 * A guard on where demo data runs is not a guard on what it says.
 */
class DemoContentTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function seederSources(): array
    {
        return array_map(
            fn ($file) => (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', File::get($file->getPathname())),
            File::files(database_path('seeders')),
        );
    }

    /**
     * Every YouTube id belongs to somebody's real video, so there is no such
     * thing as a placeholder one. A seeder that wants to demonstrate video
     * needs a real Rihla upload.
     *
     * Comments are stripped before scanning — the note recording which two
     * videos were removed names them both, and would otherwise be reported as
     * the thing it is documenting.
     */
    public function test_no_seeder_embeds_a_third_party_video(): void
    {
        $offenders = [];

        foreach ($this->seederSources() as $source) {
            if (preg_match_all('~https?://(?:www\.)?(?:youtube\.com|youtu\.be|vimeo\.com)\S*~', $source, $matches)) {
                $offenders = array_merge($offenders, $matches[0]);
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Seeders embed third-party video URLs. Every id is a real video',
                'belonging to someone; there is no placeholder one:'],
            $offenders,
        )));
    }

    /**
     * The guard that keeps demo data off the production site.
     *
     * Only the seeders that carry invented content are listed. The guide
     * seeders hold the real Umrah guide, and the hero and "why" seeders hold
     * the real homepage copy — those are content the business wants in
     * production, and guarding them would be the bug rather than the fix. An
     * earlier version of this test asserted the guard on every seeder and
     * reported all five of them.
     *
     * @var list<string>
     */
    private const DEMO_SEEDERS = ['MediaSeeder.php', 'TripSeeder.php'];

    public function test_the_demo_seeders_keep_their_production_guard(): void
    {
        $unguarded = [];

        foreach (self::DEMO_SEEDERS as $name) {
            $path = database_path('seeders/'.$name);

            $this->assertFileExists($path);

            if (! str_contains(File::get($path), 'isProduction')) {
                $unguarded[] = $name;
            }
        }

        $this->assertSame([], $unguarded, implode("\n", array_merge(
            ['Demo seeders that would now run in production:'],
            $unguarded,
        )));
    }

    /**
     * Removing the seeder's videos was not enough on its own.
     *
     * A deploy runs `migrate --force`, not `db:seed`, so the rows the old
     * seeder had already written stayed in the test database and stayed on
     * the page. Changing a seeder governs the next seed and nothing else. The
     * migration deletes them by the exact values the seeder wrote.
     */
    public function test_the_placeholder_media_is_deleted_rather_than_only_unseeded(): void
    {
        // Exactly what the old seeder inserted, put back by hand.
        Media::create([
            'type' => 'video', 'title' => 'Cultural Heritage Tour Highlights',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'is_published' => true,
        ]);
        Media::create([
            'type' => 'photo', 'title' => 'Maldives Sunset',
            'file_path' => 'media/sunset.jpg', 'is_published' => true,
        ]);

        // A real row that merely shares a title must survive.
        $keep = Media::create([
            'type' => 'video', 'title' => 'Cultural Heritage Tour Highlights',
            'video_url' => 'https://www.youtube.com/watch?v=rihla-real-upload', 'is_published' => true,
        ]);

        // Called directly: RefreshDatabase has already run every migration,
        // so `artisan migrate` would report nothing to do and assert nothing.
        $migration = require database_path('migrations/2026_09_19_060000_remove_placeholder_demo_media.php');
        $migration->up();

        $this->assertDatabaseMissing('media', ['video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $this->assertDatabaseMissing('media', ['file_path' => 'media/sunset.jpg']);
        $this->assertDatabaseHas('media', ['id' => $keep->id]);
    }

    /**
     * The trips were the worse half, and the half I missed first time.
     *
     * The gallery was cleaned while `TripSeeder` went on offering "Luxury
     * Resort Experience — overwater villas, private beaches, perfect for
     * honeymooners and luxury travelers" from an Umrah operator, each trip on
     * its own indexed URL. Trips are the most prominent content on the site:
     * the homepage lists them, the trips page lists them, and each has a page
     * of its own.
     *
     * This checks the words rather than the rows. A seeder can be rewritten
     * and still describe a beach holiday.
     */
    public function test_no_seeded_trip_sells_a_beach_holiday(): void
    {
        $offenders = [];

        // Vocabulary that has no business on a pilgrimage itinerary.
        $resort = ['overwater', 'honeymoon', 'luxury travel', 'white sandy', 'water sports',
            'private beach', 'resort experience', 'island hopping', 'gourmet dining'];

        foreach ($this->seederSources() as $source) {
            foreach ($resort as $phrase) {
                if (stripos($source, $phrase) !== false) {
                    $offenders[] = $phrase;
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), implode("\n", array_merge(
            ['Seeders describe a beach holiday. This is an Umrah operator:'],
            array_unique($offenders),
        )));
    }

    /** The cleanup deletes the rows a seeder change cannot reach. */
    public function test_the_placeholder_trips_are_deleted_rather_than_only_unseeded(): void
    {
        $gone = Trip::create([
            'title' => 'Luxury Resort Experience', 'slug' => 'luxury-resort-experience',
            'date_start' => now(), 'date_end' => now()->addDay(), 'status' => 'upcoming', 'is_published' => true,
        ]);

        // A real trip that merely shares the name must survive.
        $keep = Trip::create([
            'title' => 'Luxury Resort Experience', 'slug' => 'a-real-trip-someone-named-oddly',
            'date_start' => now(), 'date_end' => now()->addDay(), 'status' => 'upcoming', 'is_published' => true,
        ]);

        $migration = require database_path('migrations/2026_09_19_063000_remove_placeholder_demo_trips.php');
        $migration->up();

        $this->assertDatabaseMissing('trips', ['id' => $gone->id]);
        $this->assertDatabaseHas('trips', ['id' => $keep->id]);
    }

    /**
     * The third placeholder found on the live site, and the reason this test
     * now looks for the shape rather than the instance.
     *
     * `SettingsSeeder` seeded `PLxxxxxxxxxx` as the YouTube playlist id,
     * carrying a "replace with actual playlist ID" comment that nobody acted
     * on. The social page embedded a player pointed at it, so visitors got a
     * 404 inside an iframe where the videos should be.
     *
     * This one mattered more than the others: unlike the trip and media
     * seeders it has no production guard, because it seeds real configuration
     * rather than demo content. A placeholder here reaches the live site.
     */
    public function test_no_seeded_setting_holds_a_placeholder(): void
    {
        $offenders = [];

        // The shapes a "fill this in later" value takes.
        $shapes = [
            '/\bPL x{4,}/ix' => 'a YouTube playlist id of xs',
            '/\bx{5,}\b/i' => 'a run of xs standing in for a real value',
            '/\byour[-_](?:id|key|url|number)\b/i' => 'a your-something-here token',
            '/\b1234567890\b/' => 'a 1234567890 phone number',
            '/@example\.(?:com|org)\b/i' => 'an example.com address',
        ];

        $source = (string) preg_replace('~//[^\n]*|/\*.*?\*/~s', '', File::get(database_path('seeders/SettingsSeeder.php')));

        foreach ($shapes as $pattern => $description) {
            if (preg_match($pattern, $source, $match)) {
                $offenders[] = "{$match[0]} — {$description}";
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['SettingsSeeder holds placeholder values. It has no production',
                'guard, because it seeds real configuration — so these reach the',
                'live site:'],
            $offenders,
        )));
    }

    /** And the value already written to the database is cleared. */
    public function test_the_placeholder_playlist_is_cleared_rather_than_only_unseeded(): void
    {
        Setting::setSocialSettings(['youtube_playlist_id' => 'PLxxxxxxxxxx']);

        $migration = require database_path('migrations/2026_09_19_070000_clear_placeholder_youtube_playlist.php');
        $migration->up();

        $this->assertNull(Setting::getSocialSettings()['youtube_playlist_id']);

        // A real id set since is left alone.
        Setting::setSocialSettings(['youtube_playlist_id' => 'PLrealRihlaPlaylist']);
        $migration->up();

        $this->assertSame('PLrealRihlaPlaylist', Setting::getSocialSettings()['youtube_playlist_id']);
    }

    /**
     * A customer must never meet the browser's broken-image icon. Demo rows
     * carry no file on purpose, and a real upload can go missing too.
     */
    public function test_a_media_row_with_no_file_renders_a_placeholder(): void
    {
        $this->seed([SettingsSeeder::class, MediaSeeder::class]);

        $this->assertGreaterThan(0, Media::count(), 'The demo seed created no media.');

        $response = $this->get('/en/gallery')->assertOk();

        // The mark, used as the "no picture here" glyph.
        $response->assertSee('rihla-mark.svg', false);

        // And no <img> pointing into storage for a file that is not there.
        $html = $response->getContent();

        preg_match_all('~<img[^>]+src="([^"]*storage[^"]*)"~', $html, $matches);

        $missing = array_values(array_filter(
            $matches[1],
            fn ($url) => ! File::exists(public_path(parse_url($url, PHP_URL_PATH) ?? '')),
        ));

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['The gallery links images that do not exist, so they render as broken:'],
            $missing,
        )));
    }

    /**
     * The seeder invented four social accounts from one handle.
     *
     * facebook.com/rihlatravels, instagram.com/rihlatravels,
     * tiktok.com/@rihlatravels and viber.com/rihlatravels — none checked.
     * TikTok answers "Page not available", so that profile does not exist and
     * the public social page was sending visitors to it. The other three sit
     * behind login walls and cannot be verified by any script.
     *
     * A fresh install must therefore invent none of them. The owner sets the
     * real ones in Admin → Settings, and the page hides each link that is not
     * set — the same reason the YouTube playlist is seeded empty.
     */
    public function test_the_seeder_invents_no_social_accounts(): void
    {
        $this->seed(SettingsSeeder::class);

        $social = Setting::getSocialSettings();

        foreach (['facebook_url', 'instagram_url', 'tiktok_url', 'viber_url'] as $key) {
            $this->assertNull($social[$key] ?? null, "{$key} was seeded with a guess.");
        }

        // The number is not a guess; it is the business's real one.
        $this->assertSame('9607972434', $social['whatsapp_number']);
    }

    /** A link that is not set must leave no empty anchor behind. */
    public function test_the_social_page_hides_links_that_are_not_set(): void
    {
        $this->seed(SettingsSeeder::class);

        $html = (string) $this->get('/en/social')->assertOk()->getContent();

        foreach (['facebook.com', 'instagram.com', 'tiktok.com', 'viber.com'] as $domain) {
            $this->assertStringNotContainsString($domain, $html,
                "An unset social link still rendered a {$domain} anchor.");
        }
    }

    /**
     * Deploying with a link nobody has confirmed must at least be said out
     * loud. A warning, not a failure: the guess may be right, and blocking a
     * deploy over it would teach people to ignore preflight.
     */
    public function test_preflight_warns_about_unconfirmed_social_links(): void
    {
        config(['app.debug' => false, 'app.env' => 'production', 'app.url' => 'https://rihla.mv']);

        Setting::setSocialSettings([
            'whatsapp_number' => '9607972434',
            'facebook_url' => 'https://facebook.com/rihlatravels',
        ]);

        $this->artisan('rihla:preflight', ['--production' => true])
            ->expectsOutputToContain('facebook_url')
            ->assertSuccessful();
    }
}
