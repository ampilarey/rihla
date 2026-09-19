<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use App\Models\HeroBanner;
use App\Models\Media;
use App\Models\Trip;
use App\Models\User;
use App\Models\WhyFeature;
use App\Models\WhySection;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Walks every GET route and asserts none of them throws.
 *
 * Three of the defects found in this codebase were a page that returned a
 * 500 the moment anyone opened it: `route('dashboard')` was never defined,
 * `/admin/guide-steps/{id}` rendered a view that does not exist, and the
 * Umrah guide admin wrote to columns that were not there. Each was reachable
 * from the admin menu. None of them needed a clever test to catch — only one
 * that opened the page.
 *
 * The existing suite asserts specific behaviours, which means a screen nobody
 * thought to write a test for is a screen nobody opens until a customer does.
 * This is the backstop: it proves nothing about correctness, only that every
 * route answers.
 */
class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes excluded, each for a reason that is not "it fails".
     *
     * @var array<string, string>
     */
    private const SKIP = [
        // Needs a signed URL; forging one here would test the signature, not
        // the page, and EmailVerificationTest already covers it properly.
        'verification.verify' => 'requires a signed URL',
        // Serves a binary download through dompdf; covered by GuideTest.
        'guide.pdf' => 'covered directly, and slow to render twice',
    ];

    /** @var array<string, int|string> */
    private array $parameters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedOneOfEverything();
    }

    public function test_every_public_get_route_answers(): void
    {
        $failures = [];

        foreach ($this->getRoutes() as $route) {
            if ($this->needsAuth($route)) {
                continue;
            }

            $uri = $this->fill($route);
            $status = $this->get($uri)->getStatusCode();

            // Anything but a server error: a redirect to a locale or to login
            // is a correct answer, and 404 on a deliberately absent record is
            // not this test's business.
            if ($status >= 500) {
                $failures[] = "{$route->getName()} [{$uri}] returned {$status}";
            }
        }

        $this->assertSame([], $failures, implode("\n", array_merge(
            ['Public routes returned a server error:'], $failures,
        )));
    }

    /**
     * The admin panel is where every 500 found so far has been, because it is
     * the part no customer reports and no test opened.
     */
    public function test_every_admin_get_route_answers_for_a_super_admin(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $failures = [];

        foreach ($this->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.')) {
                continue;
            }

            $uri = $this->fill($route);
            $status = $this->actingAs($admin)->get($uri)->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$route->getName()} [{$uri}] returned {$status}";
            }
        }

        $this->assertSame([], $failures, implode("\n", array_merge(
            ['Admin routes returned a server error:'], $failures,
        )));
    }

    /**
     * Not a redirect to login but a 403: a signed-in customer must not be able
     * to read the panel, and the distinction is what the roles are for.
     */
    public function test_no_admin_route_opens_for_a_signed_in_non_admin(): void
    {
        $user = User::factory()->create();

        $leaks = [];

        foreach ($this->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'admin.')) {
                continue;
            }

            $uri = $this->fill($route);
            $status = $this->actingAs($user)->get($uri)->getStatusCode();

            if ($status < 400) {
                $leaks[] = "{$route->getName()} [{$uri}] returned {$status}";
            }
        }

        $this->assertSame([], $leaks, implode("\n", array_merge(
            ['Admin routes were readable by an account with no role:'], $leaks,
        )));
    }

    /** @return list<RoutingRoute> */
    private function getRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $name = (string) $route->getName();

            if ($name === '' || array_key_exists($name, self::SKIP)) {
                continue;
            }

            // Framework and package routes are not this application's to vouch
            // for, and Laravel's own health endpoint has no name anyway.
            if (str_starts_with($route->uri(), '_')) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    private function needsAuth(RoutingRoute $route): bool
    {
        return in_array('auth', $route->gatherMiddleware(), true);
    }

    /** Substitutes a real record for every parameter the URI declares. */
    private function fill(RoutingRoute $route): string
    {
        $uri = $route->uri();

        foreach ($this->parameters as $placeholder => $value) {
            $uri = str_replace('{'.$placeholder.'}', (string) $value, $uri);
        }

        return '/'.ltrim($uri, '/');
    }

    /**
     * One row per model, and the real keys they were given.
     *
     * Ids are read back rather than assumed to start at 1. MySQL does not
     * reset an auto-increment counter when a transaction rolls back, so under
     * RefreshDatabase the second test in a class sees ids starting at 2 —
     * while SQLite, which rebuilds the database each time, always starts at 1.
     * Hardcoding 1 passes locally and fails against the engine production
     * actually runs.
     */
    private function seedOneOfEverything(): void
    {
        $trip = Trip::create([
            'title' => 'A Seeded Trip',
            'slug' => 'a-seeded-trip',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'status' => 'upcoming',
            'is_published' => true,
        ]);

        $media = Media::create([
            'trip_id' => $trip->id,
            'type' => 'photo',
            'file_path' => 'media/example.jpg',
            'is_published' => true,
        ]);

        $step = GuideStep::factory()->create([
            'step_number' => 1,
            'is_published' => true,
        ]);

        $banner = HeroBanner::create(['title' => 'A Seeded Banner']);

        $section = WhySection::create(['title' => 'Why Rihla']);

        $feature = WhyFeature::create([
            'why_section_id' => $section->id,
            'title' => 'A Seeded Feature',
        ]);

        $this->parameters = [
            'locale' => 'en',
            'code' => 'dv',
            'slug' => $trip->slug,
            'trip' => $trip->id,
            'medium' => $media->id,
            'guide_step' => $step->id,
            'hero_banner' => $banner->id,
            'section' => $section->id,
            'feature' => $feature->id,
            'token' => 'a-token',
            'id' => 1,
            'hash' => 'a-hash',
        ];
    }
}
