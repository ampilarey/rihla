<?php

namespace Tests\Feature;

use App\Models\Media;
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
}
