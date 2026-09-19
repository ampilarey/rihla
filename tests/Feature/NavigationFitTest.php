<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The admin navigation was losing links off the edge of the screen.
 *
 * Signed-in staff see eight items where a visitor sees six, and both used the
 * same `md` breakpoint — so the desktop bar appeared from 768px while the
 * content needed 1280px. The header carries `overflow-x-hidden`, so the extra
 * width was clipped rather than scrolled: no scrollbar, nothing to drag, the
 * links simply absent. Measured in a browser:
 *
 *   768–1000px   Settings, View Site and Logout gone (283px cut)
 *   1100–1250px  Logout gone (27px cut)
 *   1280px+      fits
 *
 * That covers iPads in landscape, small laptops and any half-screen window.
 * Nothing failed, nothing logged, and every test passed — a link that has been
 * clipped is still in the HTML.
 */
class NavigationFitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The desktop bar may only appear once there is room for it.
     *
     * A visitor's six items fit from `md`. Staff need `xl`, and below it the
     * hamburger carries the same links.
     */
    public function test_the_signed_in_navigation_waits_for_a_wider_screen(): void
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString(
            "Auth::check() ? 'hidden xl:flex' : 'hidden md:flex'",
            $layout,
            'The navigation shares one breakpoint again; the signed-in bar needs xl.',
        );

        $this->assertStringContainsString(
            "Auth::check() ? 'xl:hidden' : 'md:hidden'",
            $layout,
            'The menu button must appear exactly where the desktop bar disappears.',
        );
    }

    /**
     * Tailwind compiles the class names it finds written out in source. A
     * class assembled from a variable reaches the browser and matches nothing
     * — which is how `w-50` sat in the header doing nothing for months.
     */
    public function test_the_breakpoint_classes_are_actually_compiled(): void
    {
        $css = File::get(File::glob(public_path('build/assets/*.css'))[0]);

        foreach (['xl\:flex', 'xl\:hidden', 'md\:flex', 'md\:hidden'] as $class) {
            $this->assertStringContainsString('.'.$class, $css,
                "The stylesheet has no .{$class} rule, so that breakpoint does nothing.");
        }
    }

    /** Whatever the breakpoint, every admin link stays reachable by menu. */
    public function test_the_menu_carries_every_admin_link(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Access::SUPER_ADMIN);

        $html = $this->actingAs($admin)->get('/en')->assertOk()->getContent();

        $menu = substr($html, strpos($html, 'id="mobile-menu"'));
        $menu = substr($menu, 0, strpos($menu, '</header>'));

        foreach (['admin.trips.index', 'admin.media.index', 'admin.settings.index', 'admin.guide-steps.index'] as $route) {
            $this->assertStringContainsString(route($route), $menu,
                "The menu is missing {$route}, so below the desktop breakpoint it is unreachable.");
        }
    }

    /** Visitor call-to-actions do not belong on the staff panel. */
    public function test_the_admin_panel_carries_no_visitor_call_to_actions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Access::SUPER_ADMIN);

        // 'Browse Catalog' used to be the marker here. It has gone entirely:
        // it linked to the WhatsApp Business catalog, and the site carries the
        // trips now, so it was sending people the wrong way.
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Message us', false)
            ->assertDontSee('Call us', false);

        // And they are still there for visitors.
        $this->get('/en')->assertOk()
            ->assertSee('Message us', false)
            ->assertSee('Call us', false);
    }
}
