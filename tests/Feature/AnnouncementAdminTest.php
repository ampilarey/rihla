<?php

namespace Tests\Feature;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\Departure;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The announcements screen — §6.2.
 *
 * The rule that matters: **writing is not sending.** An announcement
 * reaching forty households cannot be recalled, so a tour leader drafts and
 * the office decides.
 */
class AnnouncementAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subDays(2),
            'date_end' => now()->addDays(12),
        ]);
    }

    public function test_operations_can_work_the_announcements(): void
    {
        Announcement::create([
            'departure_id' => $this->departure()->getKey(),
            'headline' => 'The group reached Madinah safely.',
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListAnnouncements::class)
            ->assertOk()
            ->assertSee('reached Madinah safely');
    }

    public function test_operations_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->get(AnnouncementResource::getUrl('index'))
            ->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(AnnouncementResource::getUrl('index'))
            ->assertForbidden();
    }

    /**
     * The whole point of a separate `publish` verb.
     *
     * A leader on the ground writes what happened; putting it in front of
     * forty households at home is the office's call, and it cannot be
     * taken back.
     */
    public function test_the_tour_leader_drafts_but_does_not_send(): void
    {
        $leader = $this->staff(Access::TOUR_LEADER);

        $this->assertTrue($leader->can('announcement.create'));
        $this->assertTrue($leader->can('announcement.update'));
        $this->assertFalse($leader->can('announcement.publish'));

        $announcement = Announcement::create([
            'departure_id' => $this->departure()->getKey(),
            'headline' => 'Draft from the coach',
        ]);

        Livewire::actingAs($leader)
            ->test(ListAnnouncements::class)
            ->assertOk()
            ->assertTableActionHidden('publish', $announcement);
    }

    public function test_publishing_puts_it_in_front_of_the_families(): void
    {
        $announcement = Announcement::create([
            'departure_id' => $this->departure()->getKey(),
            'headline' => 'Flight home is on time.',
        ]);

        $this->assertFalse($announcement->isLive());

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListAnnouncements::class)
            ->callTableAction('publish', $announcement)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($announcement->fresh()->isLive());
    }

    public function test_taking_one_down_hides_it_again(): void
    {
        $announcement = Announcement::create([
            'departure_id' => $this->departure()->getKey(),
            'headline' => 'Said too soon.',
            'published_at' => now()->subHour(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListAnnouncements::class)
            ->callTableAction('unpublish', $announcement)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($announcement->fresh()->isLive());
    }

    /**
     * The status column renders a word in all three states.
     *
     * "Draft" is the null case, and a null column state short-circuits
     * `formatStateUsing()` — the exact defect that shipped on the rooming
     * screen and rendered an empty cell.
     */
    public function test_every_state_renders_a_word(): void
    {
        $departure = $this->departure();

        $draft = Announcement::create(['departure_id' => $departure->getKey(), 'headline' => 'A']);
        $sent = Announcement::create([
            'departure_id' => $departure->getKey(), 'headline' => 'B', 'published_at' => now()->subHour(),
        ]);
        $scheduled = Announcement::create([
            'departure_id' => $departure->getKey(), 'headline' => 'C', 'published_at' => now()->addDay(),
        ]);

        $component = Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListAnnouncements::class)
            ->assertOk();

        $component->assertTableColumnStateSet('published_at', 'Draft', $draft);
        $component->assertTableColumnStateSet('published_at', 'Sent '.$sent->published_at->format('j M, H:i'), $sent);
        $component->assertTableColumnStateSet('published_at', 'Goes out '.$scheduled->published_at->format('j M, H:i'), $scheduled);
    }

    /**
     * The badge counts drafts, not announcements.
     *
     * An announcement somebody wrote and never published is the failure
     * worth a number: the family is waiting for news sitting in a box.
     */
    public function test_the_badge_counts_unsent_drafts(): void
    {
        $departure = $this->departure();

        Announcement::create(['departure_id' => $departure->getKey(), 'headline' => 'Waiting']);
        Announcement::create([
            'departure_id' => $departure->getKey(), 'headline' => 'Gone', 'published_at' => now()->subHour(),
        ]);

        $this->assertSame('1', AnnouncementResource::getNavigationBadge());
    }

    public function test_a_draft_on_a_long_finished_trip_is_not_on_the_badge(): void
    {
        $old = Departure::factory()->withSeats(20)->create([
            'date_start' => now()->subMonths(4),
            'date_end' => now()->subMonths(4)->addDays(14),
        ]);

        Announcement::create(['departure_id' => $old->getKey(), 'headline' => 'Long over']);

        $this->assertNull(AnnouncementResource::getNavigationBadge());
    }
}
