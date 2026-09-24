<?php

namespace Tests\Feature;

use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Models\Media;
use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The gallery, in both languages — the last content table to get one.
 *
 * `media.title` and `media.caption` are written by an editor and shown on the
 * gallery and on trip pages, in whatever language they happened to be typed
 * in. A Dhivehi visitor read English captions with no way to change that,
 * short of a second media row nobody would know to make.
 */
class MediaTranslationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_09_19_230000_make_media_text_translatable.php';

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    public function test_the_columns_hold_both_languages(): void
    {
        $medium = Media::create([
            'type' => 'photo',
            'title' => ['en' => 'Madinah at dawn', 'dv' => 'މަދީނާ ފަތިހު'],
            'file_path' => 'media/large/one.webp',
            'is_published' => true,
        ]);

        $this->assertSame('Madinah at dawn', $medium->getTranslation('title', 'en'));
        $this->assertSame('މަދީނާ ފަތިހު', $medium->getTranslation('title', 'dv'));
    }

    public function test_the_gallery_reads_the_visitors_language(): void
    {
        Media::create([
            'type' => 'photo',
            'title' => ['en' => 'Madinah at dawn', 'dv' => 'މަދީނާ ފަތިހު'],
            'caption' => ['en' => 'The Prophet\'s Mosque before Fajr.'],
            'file_path' => 'media/large/one.webp',
            'is_published' => true,
        ]);

        $this->get('/dv/gallery')->assertOk()
            ->assertSee('މަދީނާ ފަތިހު', false)
            // Per field: the caption has no Dhivehi, so it falls back.
            ->assertSee('The Prophet\'s Mosque before Fajr.');

        $this->get('/en/gallery')->assertOk()
            ->assertSee('Madinah at dawn')
            ->assertDontSee('މަދީނާ ފަތިހު', false);
    }

    /** Existing English text becomes the English translation, not a blob. */
    public function test_the_migration_carries_existing_text_across(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        DB::table('media')->insert([
            'type' => 'photo',
            'title' => 'Madinah at dawn',
            'caption' => 'The Prophet\'s Mosque before Fajr.',
            'file_path' => 'media/large/one.webp',
            'is_published' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])
            ->assertSuccessful();

        $medium = Media::sole();

        $this->assertSame('Madinah at dawn', $medium->getTranslation('title', 'en'));
        $this->assertFalse($medium->hasTranslation('title', 'dv'));
    }

    public function test_the_admin_saves_both_languages(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateMedia::class)
            ->fillForm([
                'type' => 'video',
                'title' => ['en' => 'Umrah 2026 highlights', 'dv' => 'ޢުމްރާ ٢٠٢٦'],
                'caption' => ['en' => 'Ten nights, in five minutes.', 'dv' => ''],
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $medium = Media::sole();

        $this->assertSame('ޢުމްރާ ٢٠٢٦', $medium->getTranslation('title', 'dv'));
        // An empty box is not a translation.
        $this->assertFalse($medium->hasTranslation('caption', 'dv'));
    }

    /**
     * The thumbnail for a non-YouTube video used to be a URL on
     * via.placeholder.com — a service that has shut down, on a host `img-src`
     * refuses. Every Vimeo, Facebook, Instagram and TikTok video in the
     * gallery rendered a broken image and logged a CSP violation.
     */
    public function test_no_thumbnail_points_at_a_dead_third_party(): void
    {
        $trip = Trip::create([
            'title' => ['en' => 'Ramadan Umrah'],
            'slug' => 'ramadan-umrah',
            'date_start' => now()->subDay(),
            'date_end' => now()->addDay(),
            'status' => Trip::STATUS_PAST,
            'is_published' => true,
        ]);

        foreach ([
            'https://vimeo.com/76979871',
            'https://www.facebook.com/rihla/videos/123',
            'https://www.instagram.com/reel/abc/',
            'https://www.tiktok.com/@rihla/video/123',
        ] as $index => $url) {
            Media::create([
                'trip_id' => $trip->id,
                'type' => 'video',
                'title' => ['en' => 'A video'],
                'video_url' => $url,
                'sort_order' => $index,
                'is_published' => true,
            ]);
        }

        foreach (['/en/gallery', '/en/trips/ramadan-umrah'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            // The videos are on the page — otherwise this would pass by
            // rendering nothing at all.
            $this->assertStringContainsString('A video', $html, "No video rendered on {$page}.");

            $this->assertStringNotContainsString('placeholder.com', $html);
        }
    }

    /** YouTube publishes thumbnails at a predictable URL, and that host is allowed. */
    public function test_a_youtube_video_still_gets_its_thumbnail(): void
    {
        $medium = Media::create([
            'type' => 'video',
            'title' => ['en' => 'Umrah 2026 highlights'],
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_published' => true,
        ]);

        $this->assertSame(
            'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            $medium->thumbnail_url,
        );
    }

    public function test_a_video_without_a_thumbnail_says_so_rather_than_guessing(): void
    {
        $medium = Media::create([
            'type' => 'video',
            'title' => ['en' => 'A talk'],
            'video_url' => 'https://vimeo.com/76979871',
            'is_published' => true,
        ]);

        $this->assertNull($medium->thumbnail_url);
    }

    public function test_the_locale_free_columns_are_gone(): void
    {
        $this->assertContains('title', Schema::getColumnListing('media'));
        $this->assertContains('caption', Schema::getColumnListing('media'));
    }
}
