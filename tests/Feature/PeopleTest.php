<?php

namespace Tests\Feature;

use App\Models\Departure;
use App\Models\Package;
use App\Models\Person;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Group leaders and scholars.
 *
 * "Pilgrims choose people, not packages" — §4.3. A first-time pilgrim
 * choosing between two operators is largely deciding whether they trust
 * whoever will be standing beside them at the miqat, and nobody in this
 * market publishes who that is.
 */
class PeopleTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaders_and_scholars_are_listed_separately(): void
    {
        Person::factory()->create(['name' => 'Ahmed Shakir']);
        Person::factory()->scholar()->create(['name' => 'Sheikh Ibrahim']);

        $this->get('/en/people')
            ->assertOk()
            ->assertSee('Group leaders')
            ->assertSee('Ahmed Shakir')
            ->assertSee('Scholars')
            ->assertSee('Sheikh Ibrahim');
    }

    public function test_an_unpublished_person_is_not_shown(): void
    {
        Person::factory()->unpublished()->create(['name' => 'Not ready']);

        $this->get('/en/people')->assertOk()->assertDontSee('Not ready');
    }

    public function test_languages_are_listed_because_that_is_the_practical_question(): void
    {
        Person::factory()->create([
            'name' => 'Ahmed Shakir',
            'languages' => ['en' => ['Dhivehi', 'English', 'Arabic']],
        ]);

        $this->get('/en/people')->assertOk()->assertSee('Dhivehi, English, Arabic');
    }

    /**
     * Blank means nobody has counted. Rendering that as 0 would claim they
     * have led none, which is a different and worse statement.
     */
    public function test_an_uncounted_leader_shows_no_number_rather_than_zero(): void
    {
        Person::factory()->create(['name' => 'Uncounted', 'groups_led' => null]);

        $this->get('/en/people')
            ->assertOk()
            ->assertSee('Uncounted')
            ->assertDontSee('Groups led');
    }

    public function test_a_counted_leader_shows_the_count(): void
    {
        Person::factory()->create(['name' => 'Counted', 'groups_led' => 14]);

        $this->get('/en/people')->assertOk()->assertSee('Groups led')->assertSee('14');
    }

    /** No photograph is the ordinary case, and must not be a broken image. */
    public function test_a_person_without_a_photograph_gets_drawn_initials(): void
    {
        Person::factory()->create(['name' => 'Ahmed Shakir', 'photo_path' => null]);

        $this->get('/en/people')
            ->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('ui-avatars.com')
            ->assertDontSee('gravatar');
    }

    // ── On the package page ──────────────────────────────────────────────

    public function test_a_departure_names_who_travels_with_it(): void
    {
        $package = Package::factory()->create();
        $leader = Person::factory()->create(['name' => 'Ahmed Shakir']);
        $scholar = Person::factory()->scholar()->create(['name' => 'Sheikh Ibrahim']);

        Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(40),
            'tour_leader_id' => $leader->id,
            'scholar_id' => $scholar->id,
        ]);

        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertSee('Travelling with you')
            ->assertSee('Ahmed Shakir')
            ->assertSee('Sheikh Ibrahim');
    }

    public function test_a_departure_with_nobody_named_says_nothing(): void
    {
        $package = Package::factory()->create();
        Departure::factory()->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(40),
        ]);

        // Publishing a leader nobody has named is worse than publishing none.
        $this->get('/en/packages/'.$package->slug)
            ->assertOk()
            ->assertDontSee('Travelling with you');
    }

    /** Removing a person must not take their departures with them. */
    public function test_deleting_a_person_leaves_the_departure_standing(): void
    {
        $package = Package::factory()->create();
        $leader = Person::factory()->create();

        $departure = Departure::factory()->create([
            'package_id' => $package->id,
            'tour_leader_id' => $leader->id,
        ]);

        $leader->delete();

        $this->assertNull($departure->refresh()->tour_leader_id);
        $this->assertDatabaseHas('departures', ['id' => $departure->id]);
    }

    // ── Admin ────────────────────────────────────────────────────────────

    public function test_a_content_manager_can_manage_people(): void
    {
        $editor = User::factory()->create()->assignRole(Access::CONTENT_MANAGER);

        $this->actingAs($editor)->get('/staff/people')->assertOk();
    }

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $leader = User::factory()->create()->assignRole(Access::TOUR_LEADER);

        $this->actingAs($leader)->get('/staff/people')->assertForbidden();
    }

    /**
     * A person's name is their name. Transliterating it into Thaana
     * automatically is how machine-generated Dhivehi got onto this site.
     */
    public function test_the_name_is_not_translatable(): void
    {
        $this->assertNotContains('name', (new Person)->translatable);
    }

    public function test_the_page_is_reachable_without_crowding_the_header(): void
    {
        Person::factory()->create();

        // In the footer, not the nav: an eighth desktop link would push every
        // visitor on a laptop onto the hamburger to gain one page.
        $this->get('/en')->assertOk()->assertSee(route('people.index'), false);
    }
}
