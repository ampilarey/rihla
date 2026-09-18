<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Contact;
use App\Support\Seo;
use Database\Seeders\HeroBannerSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\TripSeeder;
use Database\Seeders\UmrahGuideSeeder;
use Database\Seeders\WhySectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Rihla's phone number was written out by hand in thirteen places.
 *
 * Twelve hard-coded 9607972434 and never consulted the WhatsApp number in
 * Admin → Settings at all — among them the floating button on every page, the
 * sticky contact bar and the footer. Changing the number in the admin panel
 * moved four links and left the rest pointing at the old one, which is the
 * kind of defect nobody notices until the enquiries stop arriving.
 *
 * The thirteenth was worse: the guide page's "I need help with the Umrah
 * guide" button read `config('app.whatsapp', '1234567890')`, and no such
 * config key has ever existed. That button pointed at the literal
 * placeholder 1234567890, live, in both languages.
 */
class ContactNumberTest extends TestCase
{
    use RefreshDatabase;

    /** Public pages that offer a way to make contact. */
    private const PAGES = ['/en', '/en/trips', '/en/contact', '/en/social', '/en/guide', '/dv', '/dv/guide'];

    private function seedContent(): void
    {
        $this->seed([
            SettingsSeeder::class,
            TripSeeder::class,
            HeroBannerSeeder::class,
            WhySectionSeeder::class,
            UmrahGuideSeeder::class,
        ]);
    }

    public function test_no_public_view_writes_a_phone_number_by_hand(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/views/admin/')) {
                continue;   // The admin form legitimately shows an example.
            }

            foreach (['wa.me/', 'tel:'] as $needle) {
                if (str_contains(File::get($file), $needle)) {
                    $offenders[] = basename($path).' writes "'.$needle.'" directly';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Views must build contact links through App\Support\Contact, so that',
                'changing the number in Admin → Settings changes every link:'],
            $offenders,
        )));
    }

    /**
     * The one that matters to the business: change the number in the admin
     * panel, and every page follows.
     */
    public function test_changing_the_admin_setting_moves_every_contact_link(): void
    {
        $this->seedContent();

        Setting::setSocialSettings([
            'facebook_url' => null,
            'instagram_url' => null,
            'tiktok_url' => null,
            'whatsapp_number' => '9601112222',
            'viber_url' => null,
            'youtube_playlist_id' => null,
        ]);

        foreach (self::PAGES as $page) {
            $html = $this->get($page)->getContent();

            $this->assertStringContainsString('wa.me/9601112222', $html,
                "{$page} does not link to the configured WhatsApp number.");

            $this->assertStringNotContainsString('9607972434', $html,
                "{$page} still carries the old hard-coded number.");
        }
    }

    public function test_the_guide_help_button_is_not_a_placeholder(): void
    {
        $this->seedContent();

        foreach (['/en/guide', '/dv/guide'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertDontSee('1234567890', false)
                ->assertSee('wa.me/'.Contact::FALLBACK, false);
        }
    }

    /**
     * config('app.whatsapp') was read by a live view and has never been
     * defined. A default in the second argument hides that completely.
     */
    public function test_no_view_reads_a_config_key_that_does_not_exist(): void
    {
        $missing = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all("/config\('([a-z0-9_.]+)'/", File::get($file), $matches);

            foreach ($matches[1] as $key) {
                if (config($key) === null) {
                    $missing[] = basename($file->getPathname()).": config('{$key}')";
                }
            }
        }

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['Views read config keys that resolve to null:'],
            $missing,
        )));
    }

    public function test_a_number_typed_with_spaces_and_a_plus_still_builds_a_valid_link(): void
    {
        Setting::setSocialSettings(['whatsapp_number' => '+960 797-2434']);

        // The admin field is free text capped at 20 characters, so this is
        // an accepted input. wa.me accepts digits only.
        $this->assertSame('9607972434', Contact::whatsappNumber());
        $this->assertSame('https://wa.me/9607972434', Contact::whatsappUrl());
        $this->assertSame('tel:+9607972434', Contact::telUrl());
        $this->assertSame('+960 797 2434', Contact::displayNumber());
    }

    public function test_an_empty_number_falls_back_rather_than_linking_to_nothing(): void
    {
        Setting::setSocialSettings(['whatsapp_number' => '']);

        $this->assertSame(Contact::FALLBACK, Contact::whatsappNumber());
    }

    public function test_the_message_is_url_encoded_into_the_link(): void
    {
        $url = Contact::whatsappUrl('I need help with the Umrah guide');

        $this->assertStringContainsString('?text=I%20need%20help', $url);
    }

    /**
     * Every caller wants the same single row. Before this, each call issued
     * its own query — the contact page ran nine identical selects against
     * `settings`, which was its entire query budget for the page.
     */
    public function test_the_settings_row_is_read_once_per_request(): void
    {
        $this->seedContent();

        foreach (self::PAGES as $page) {
            $queries = [];

            DB::listen(function ($query) use (&$queries) {
                if (str_contains($query->sql, '"settings"') || str_contains($query->sql, '`settings`')) {
                    $queries[] = $query->sql;
                }
            });

            $this->get($page);

            $this->assertLessThanOrEqual(1, count($queries), sprintf(
                '%s ran %d queries against `settings` to render one page.',
                $page,
                count($queries),
            ));
        }
    }

    /**
     * The structured data reaches furthest of all: Google caches it and
     * shows it in the knowledge panel, long after the site itself is fixed.
     */
    public function test_the_structured_data_publishes_the_configured_number(): void
    {
        $this->seedContent();

        Setting::setSocialSettings(['whatsapp_number' => '9605556666']);

        $this->assertSame('+9605556666', Seo::contactPhone());

        $this->get('/en')
            ->assertSee('"telephone":"+9605556666"', false)
            ->assertDontSee('+9607972434', false);
    }

    /** A write must not leave the memo showing the old number. */
    public function test_saving_settings_invalidates_the_memo(): void
    {
        $this->seedContent();

        $this->get('/en');   // Populates the memo.

        Setting::setSocialSettings(['whatsapp_number' => '9603334444']);

        $this->assertSame('9603334444', Contact::whatsappNumber());
        $this->get('/en')->assertSee('wa.me/9603334444', false);
    }

    /**
     * The settings row is a free-form JSON blob and the views read it with
     * direct array access. A row saved without one of its keys used to take
     * down every public page with "Undefined array key".
     */
    public function test_a_settings_row_missing_keys_does_not_break_the_site(): void
    {
        $this->seedContent();

        Setting::setSocialSettings(['whatsapp_number' => '9607778888']);

        foreach (self::PAGES as $page) {
            $this->get($page)->assertOk();
        }

        $this->assertSame(
            array_keys(Setting::socialDefaults()),
            array_keys(Setting::getSocialSettings()),
            'getSocialSettings() must always return the full shape.',
        );
    }

    /** Opening a new tab hands the opener to the destination without this. */
    public function test_links_that_open_a_new_tab_carry_rel_noopener(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/views/admin/') || str_contains($path, '/views/pdf/')) {
                continue;
            }

            preg_match_all('/<a\b[^>]*?>/s', File::get($file), $matches);

            foreach ($matches[0] as $tag) {
                if (str_contains($tag, 'target="_blank"') && ! str_contains($tag, 'rel=')) {
                    $offenders[] = basename($path).': '.substr(preg_replace('/\s+/', ' ', $tag), 0, 80);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Links opening a new tab must carry rel="noopener":'],
            $offenders,
        )));
    }
}
