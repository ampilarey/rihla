<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
     * The defect itself. A title carrying both an apostrophe and a double
     * quote must arrive in the confirmation intact.
     */
    public function test_a_delete_confirmation_survives_a_title_with_quotes_in_it(): void
    {
        $title = 'Ahmed\'s "Umrah" photo';

        Media::create(['title' => $title, 'type' => 'photo', 'is_published' => true]);

        $this->actingAs($this->admin())
            ->get('/admin/media')
            ->assertOk()
            ->assertSee('Are you sure you want to delete this media? — '.$title, escape: true);
    }

    /** Every destructive admin form must ask first. */
    public function test_admin_delete_forms_ask_before_they_destroy(): void
    {
        $admin = $this->admin();

        Media::create(['title' => 'A photo', 'type' => 'photo', 'is_published' => true]);

        $html = $this->actingAs($admin)->get('/admin/media')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*admin\/media\/\d+[^>]*data-confirm="/s',
            (string) $html,
            'The media delete form must carry data-confirm.',
        );
    }

    /**
     * Arguments travel as JSON now. If any of them is not valid JSON once
     * rendered, the dispatcher silently refuses to call the handler and the
     * button does nothing — which is how the old escaping bug looked, too.
     */
    public function test_every_rendered_data_args_is_valid_json(): void
    {
        $admin = $this->admin();

        $pages = ['/en', '/en/trips', '/en/guide'];

        foreach ($pages as $page) {
            $this->assertDataArgsAreJson((string) $this->get($page)->getContent(), $page);
        }

        // `/admin/hero-banners` and `/admin/guide-steps` left this list with
        // their screens (§9.2). The Filament pages that replaced them render
        // no `data-args` at all, and the old URLs only redirect — so kept
        // here they would pass by checking an empty body, which is a guard
        // reporting green about nothing.
        foreach (['/admin/media'] as $page) {
            $html = (string) $this->actingAs($admin)->get($page)->getContent();
            $this->assertDataArgsAreJson($html, $page);
        }
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
