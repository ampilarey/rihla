<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The Filament staff panel at /staff.
 *
 * It exists so new admin modules are not hand-written Blade. The existing
 * Blade panel keeps /admin and its six models until Phase 3 — see
 * docs/adr/0003-filament-for-new-admin-modules.md.
 */
class StaffPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_the_sites_one_login(): void
    {
        // Not to a second login form of Filament's own: one place to get
        // session handling, throttling and password resets right.
        $this->get('/staff')->assertRedirect(route('login'));

        $this->get('/staff/login')->assertNotFound();
    }

    /**
     * Being signed in is not enough, and must not become enough: every
     * customer account Phase 3 introduces will be an authenticated user.
     */
    public function test_a_signed_in_user_without_staff_access_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->get('/staff')->assertForbidden();
    }

    public function test_a_staff_member_can_open_the_panel(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->actingAs($admin)->get('/staff')->assertOk()->assertSee('Rihla Staff');
    }

    /** A role with content permissions carries `admin.access` too. */
    public function test_a_content_manager_can_open_the_panel(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)->get('/staff')->assertOk();
    }

    /** The Blade panel is untouched and still owns /admin. */
    public function test_the_blade_admin_still_owns_admin(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->actingAs($admin)->get(route('admin.trips.index'))->assertOk();
    }

    /**
     * Installing Filament adds two routes to the *public* surface, outside
     * the panel's middleware. Without the tables they bind against they
     * answered 500 to anyone who guessed the URL, which is how they were
     * found — the route smoke test objected.
     */
    public function test_filaments_public_download_routes_do_not_error(): void
    {
        foreach (['/filament/exports/1/download', '/filament/imports/1/failed-rows/download'] as $uri) {
            $status = $this->get($uri)->getStatusCode();

            $this->assertLessThan(500, $status, "{$uri} returned {$status}");
        }
    }

    /**
     * The panel cannot run under the site's nonce-based script policy —
     * Filament writes `window.filamentData` in an inline <script> with no way
     * to attach a nonce — so that one directive is relaxed for /staff and
     * nowhere else. Both halves matter, so both are asserted.
     */
    public function test_only_the_staff_panel_relaxes_the_script_policy(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $panel = $this->actingAs($admin)->get('/staff')
            ->assertOk()
            ->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $panel);
        $this->assertStringNotContainsString('nonce-', $panel,
            'A nonce alongside unsafe-inline makes the browser ignore unsafe-inline.');

        $public = $this->get('/en')->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'nonce-", $public,
            'The public site must keep the strict script policy.');
        $this->assertStringNotContainsString("'unsafe-inline' 'unsafe-eval'", $public);
    }

    /**
     * Every inline script the panel renders would be refused under a nonce
     * policy, which is the whole reason for the exception above. If a future
     * Filament release starts nonce-ing them, this fails and the exception
     * can go.
     */
    public function test_the_panel_still_needs_the_exception(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $html = $this->actingAs($admin)->get('/staff')->assertOk()->getContent();

        preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>/', $html, $matches);

        $withoutNonce = array_filter($matches[0], fn (string $tag) => ! str_contains($tag, 'nonce'));

        $this->assertNotEmpty($withoutNonce,
            'The panel now nonces its inline scripts; drop the CSP exception in SecurityHeaders.');
    }

    /**
     * Filament's default avatar is an <img> pointing at ui-avatars.com: a
     * third-party request carrying a staff member's name, which `img-src`
     * refuses anyway.
     */
    public function test_avatars_are_drawn_locally(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $html = $this->actingAs($admin)->get('/staff')->assertOk()->getContent();

        $this->assertStringNotContainsString('ui-avatars.com', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html);
    }

    /**
     * The cPanel constraint, asserted rather than remembered.
     *
     * There is no Node and no build step on the server, and the deploy is a
     * `git pull`. Filament's CSS, JS and fonts are published into public/ by
     * `filament:assets`, so they have to be committed exactly like
     * public/build — `filament:install` had added all three directories to
     * .gitignore, which would have shipped a panel with no styles.
     */
    public function test_the_published_assets_are_present_and_not_ignored(): void
    {
        foreach ([
            'public/css/filament/filament/app.css',
            'public/js/filament/filament/app.js',
            'public/js/filament/support/support.js',
        ] as $asset) {
            $this->assertTrue(File::exists(base_path($asset)), "{$asset} has not been published.");
        }

        $ignored = File::get(base_path('.gitignore'));

        foreach (['/public/css/filament', '/public/js/filament', '/public/fonts/filament'] as $directory) {
            $this->assertStringNotContainsString(
                $directory."\n", $ignored,
                "{$directory} is git-ignored; the deploy is a git pull, so the panel would ship unstyled.",
            );
        }
    }

    /**
     * Committed assets go stale silently.
     *
     * `composer update` brings a new Filament, `filament:upgrade` republishes
     * the assets — and if nobody commits the result, the server keeps serving
     * the previous version's JavaScript against the new version's markup. The
     * published files are byte-for-byte copies of files the packages ship, so
     * that is checkable: every committed asset must still match something in
     * vendor.
     */
    public function test_the_committed_assets_match_what_the_packages_ship(): void
    {
        $shipped = [];

        foreach (File::directories(base_path('vendor/filament')) as $package) {
            if (! File::isDirectory($package.'/dist')) {
                continue;
            }

            foreach (File::allFiles($package.'/dist') as $file) {
                $shipped[md5_file($file->getPathname())] = true;
            }
        }

        $this->assertNotEmpty($shipped, 'No Filament dist files found to compare against.');

        $stale = [];

        foreach (['public/js/filament', 'public/css/filament', 'public/fonts/filament'] as $directory) {
            if (! File::isDirectory(base_path($directory))) {
                continue;
            }

            foreach (File::allFiles(base_path($directory)) as $file) {
                if (! isset($shipped[md5_file($file->getPathname())])) {
                    $stale[] = $directory.'/'.$file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $stale, implode("\n", array_merge([
            'Committed Filament assets no longer match the installed packages.',
            'Run `php artisan filament:upgrade` and commit the result:',
        ], $stale)));
    }
}
