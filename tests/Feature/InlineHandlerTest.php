<?php

namespace Tests\Feature;

use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Behaviour belongs in a script block, not in an attribute.
 *
 * Thirty-six onclick and onsubmit attributes were spread across fourteen
 * views. They had to go before any Content-Security-Policy could be written —
 * a nonce lets the browser trust a <script> block this application wrote, and
 * cannot vouch for code living in an attribute — but the reason they had to go
 * first was a live defect, not a future one.
 *
 * The media delete button built a JavaScript string literal out of a media
 * title, inside an HTML attribute. Blade escapes an apostrophe to &#039; and
 * the HTML parser turns it back into an apostrophe before JavaScript sees it,
 * so the handler would not compile. Checked in a browser: `onclick` stayed
 * null. A handler that never compiles cannot `return false`, the button was
 * still type="submit" inside the form, and the item was deleted with no
 * confirmation at all — on exactly the titles a person is most likely to
 * write.
 */
class InlineHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /** @return list<string> */
    private function views(): array
    {
        return array_map(
            fn ($file) => $file->getPathname(),
            File::allFiles(resource_path('views')),
        );
    }

    public function test_no_view_carries_an_inline_event_handler(): void
    {
        $offenders = [];

        foreach ($this->views() as $view) {
            if (preg_match_all('/\son(click|submit|change|load|error|focus|blur)\s*=/i', (string) File::get($view), $m)) {
                $offenders[] = basename($view).' ('.count($m[0]).')';
            }
        }

        $this->assertSame([], $offenders,
            'Inline handlers cannot be covered by a CSP nonce, and interpolating into them is an escaping trap.');
    }

    /**
     * The defect itself, on the screen that replaced the one it lived on.
     * The media list moved to the staff panel (§9.2), whose delete is a
     * Livewire action behind a confirmation modal rather than a form with a
     * handler built from the title — so the escaping trap cannot recur
     * there. What still has to hold is the outcome: a title carrying both
     * an apostrophe and a double quote is deleted only after the modal, and
     * the modal is actually there.
     */
    public function test_deleting_a_media_item_with_quotes_in_its_title_asks_first(): void
    {
        $medium = Media::create(['title' => 'Ahmed\'s "Umrah" photo', 'type' => 'photo', 'is_published' => true]);

        Livewire::actingAs($this->admin())
            ->test(ListMedia::class)
            ->mountTableAction('delete', $medium)
            ->assertActionMounted(TestAction::make('delete')->table($medium));

        // Mounting opened the modal and deleted nothing.
        $this->assertModelExists($medium);
    }

    /** The last Blade delete form must still ask first. */
    public function test_admin_delete_forms_ask_before_they_destroy(): void
    {
        $trip = Trip::create([
            'title' => ['en' => 'Ramadan Umrah'],
            'slug' => 'ramadan-umrah',
            'date_start' => now()->addMonth(),
            'date_end' => now()->addMonth()->addDays(10),
            'status' => Trip::STATUS_UPCOMING,
            'is_published' => true,
        ]);

        $html = $this->actingAs($this->admin())->get('/admin/trips')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*admin\/trips\/'.$trip->slug.'"[^>]*data-confirm="/s',
            (string) $html,
            'The trip delete form must carry data-confirm.',
        );
    }

    /**
     * Arguments travel as JSON now. If any of them is not valid JSON once
     * rendered, the dispatcher silently refuses to call the handler and the
     * button does nothing — which is how the old escaping bug looked, too.
     */
    public function test_every_rendered_data_args_is_valid_json(): void
    {
        $pages = ['/en', '/en/trips', '/en/guide'];

        foreach ($pages as $page) {
            $this->assertDataArgsAreJson((string) $this->get($page)->getContent(), $page);
        }

        // `/admin/hero-banners`, `/admin/guide-steps` and `/admin/media`
        // left this list with their screens (§9.2). The Filament pages that
        // replaced them render no `data-args` at all, and the old URLs only
        // redirect — so kept here they would pass by checking an empty body,
        // which is a guard reporting green about nothing.
    }

    private function assertDataArgsAreJson(string $html, string $page): void
    {
        preg_match_all('/data-args="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

            $this->assertIsArray(json_decode($decoded, true),
                "data-args on {$page} is not valid JSON: {$decoded}");
        }
    }

    /**
     * The media index shipped a script that logged the CSRF token, every form
     * action and every form field to the browser console on each page load.
     * Debug scaffolding has reached production three times in this project.
     */
    public function test_no_view_logs_to_the_console(): void
    {
        $offenders = [];

        foreach ($this->views() as $view) {
            if (str_contains((string) File::get($view), 'console.log')) {
                $offenders[] = basename($view);
            }
        }

        $this->assertSame([], $offenders, 'Debug logging must not ship.');
    }

    /**
     * Two guest layouts both answered to <x-guest-layout>. The class-based one
     * won, and it was the worse of the pair: it loaded Figtree, which no
     * stylesheet or Tailwind config references, did not load the Inter that
     * `font-sans` actually asks for, carried no dir attribute for Dhivehi and
     * showed no logo. Password reset and registration were the pages affected.
     */
    public function test_the_guest_pages_are_branded_and_load_the_font_they_use(): void
    {
        $html = (string) $this->get('/forgot-password')->assertOk()->getContent();

        $this->assertStringContainsString('family=inter', $html, 'The guest pages must load the font they render in.');
        $this->assertStringNotContainsString('figtree', strtolower($html), 'Figtree is referenced by nothing.');
        $this->assertStringContainsString('rihla-mark', $html, 'The guest pages must carry the logo.');
        $this->assertStringContainsString('dir="ltr"', $html);
    }

    /**
     * `--optimize-autoloader` writes a class => file map. A commit that deletes
     * a PHP class leaves that map pointing at a file which is gone, and Blade
     * calls class_exists() for every <x-component> tag — so the autoloader
     * tries to include the missing file and the page fatals. Both deploy
     * scripts skip `composer install` when dependencies have not changed,
     * which is how this branch's deleted component would have taken down the
     * auth pages on the server while working perfectly here.
     */
    public function test_both_deploy_scripts_rebuild_the_classmap(): void
    {
        foreach (['scripts/pull-deploy-test.sh', 'scripts/deploy-production.sh'] as $script) {
            $this->assertStringContainsString('dump-autoload', File::get(base_path($script)),
                "{$script} can leave a stale classmap after a class is deleted.");
        }
    }
}
