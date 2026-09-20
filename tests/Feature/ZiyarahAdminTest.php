<?php

namespace Tests\Feature;

use App\Filament\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Ziyarah\Pages\EditZiyarahLocation;
use App\Filament\Resources\Ziyarah\Pages\ListZiyarahLocations;
use App\Filament\Resources\Ziyarah\RelationManagers\MisconceptionsRelationManager;
use App\Filament\Resources\Ziyarah\ZiyarahLocationResource;
use App\Models\ArticleReference;
use App\Models\LocationMisconception;
use App\Models\Person;
use App\Models\User;
use App\Models\ZiyarahLocation;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Ziyarah Guide screens — §7.2, §6.4.
 *
 * The separation these hold down is the same one the Knowledge Centre's
 * tests hold: **the scholar who signs a location off cannot put it on the
 * site, and the office that publishes cannot sign it off.** A location page
 * asserts history, significance and etiquette, so nothing about §7.2 makes
 * it a lighter claim than §7.1's.
 */
class ZiyarahAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function sourcedLocation(): ZiyarahLocation
    {
        $location = ZiyarahLocation::factory()->create();

        ArticleReference::factory()->create([
            'referenceable_type' => ZiyarahLocation::class,
            'referenceable_id' => $location->getKey(),
        ]);

        return $location->fresh();
    }

    // ── The separation §6.4 requires ─────────────────────────────────────

    public function test_the_scholar_signs_off_and_cannot_publish(): void
    {
        $scholar = $this->staff(Access::SCHOLAR);

        $this->assertTrue($scholar->can('ziyarah.review'));
        $this->assertFalse($scholar->can('ziyarah.publish'));
        $this->assertFalse($scholar->can('ziyarah.create'));
    }

    public function test_operations_publishes_and_cannot_sign_off(): void
    {
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        $this->assertTrue($operations->can('ziyarah.publish'));
        $this->assertFalse($operations->can('ziyarah.review'));
    }

    public function test_the_content_manager_writes_and_does_neither(): void
    {
        $content = $this->staff(Access::CONTENT_MANAGER);

        $this->assertTrue($content->can('ziyarah.create'));
        $this->assertTrue($content->can('ziyarah.update'));
        $this->assertFalse($content->can('ziyarah.review'));
        $this->assertFalse($content->can('ziyarah.publish'));
    }

    /** The check that would catch somebody quietly widening a role later. */
    public function test_no_role_holds_both_review_and_publish(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $user = $this->staff($role);

            $this->assertFalse(
                $user->can('ziyarah.review') && $user->can('ziyarah.publish'),
                "[{$role}] can sign off a location page and publish it, which makes the review a formality.",
            );
        }
    }

    /** No delete verb: a withdrawn location keeps its reason. */
    public function test_there_is_no_delete_permission(): void
    {
        $this->assertNotContains('ziyarah.delete', Access::PERMISSIONS);
    }

    // ── The screens agree with the model ─────────────────────────────────

    public function test_the_scholar_sees_the_sign_off_action_and_not_publish(): void
    {
        $location = $this->sourcedLocation();
        $location->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListZiyarahLocations::class)
            ->assertOk()
            ->assertTableActionVisible('approve', $location->fresh())
            ->assertTableActionHidden('publish', $location->fresh());
    }

    public function test_operations_sees_publish_only_once_it_is_approved(): void
    {
        $location = $this->sourcedLocation();
        $operations = $this->staff(Access::OPERATIONS_MANAGER);

        Livewire::actingAs($operations)
            ->test(ListZiyarahLocations::class)
            ->assertTableActionHidden('publish', $location);

        $location->approve(Person::factory()->create());

        Livewire::actingAs($operations)
            ->test(ListZiyarahLocations::class)
            ->assertTableActionVisible('publish', $location->fresh());
    }

    public function test_signing_off_an_unsourced_location_leaves_it_in_review(): void
    {
        $location = ZiyarahLocation::factory()->create();
        $location->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListZiyarahLocations::class)
            ->callTableAction('approve', $location->fresh(), [
                'scholar_id' => Person::factory()->create()->getKey(),
            ]);

        $this->assertSame(ZiyarahLocation::IN_REVIEW, $location->fresh()->status);
    }

    /**
     * The correction with no source stops it too, through the screen.
     *
     * The model refuses either way; this proves the action does not have a
     * path round it — which is how an unsourced correction would otherwise
     * reach a pilgrim who is being asked to believe it over what they were
     * already told.
     */
    public function test_signing_off_is_refused_while_a_correction_has_no_source(): void
    {
        $location = $this->sourcedLocation();

        LocationMisconception::factory()->create(['ziyarah_location_id' => $location->getKey()]);

        $location->sendForReview();

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListZiyarahLocations::class)
            ->callTableAction('approve', $location->fresh(), [
                'scholar_id' => Person::factory()->create()->getKey(),
            ]);

        $this->assertSame(ZiyarahLocation::IN_REVIEW, $location->fresh()->status);
    }

    public function test_signing_off_a_ready_location_works_and_names_the_scholar(): void
    {
        $location = $this->sourcedLocation();
        $location->sendForReview();

        $named = Person::factory()->create(['name' => 'Sheikh Placeholder']);

        Livewire::actingAs($this->staff(Access::SCHOLAR))
            ->test(ListZiyarahLocations::class)
            ->callTableAction('approve', $location->fresh(), ['scholar_id' => $named->getKey()])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ZiyarahLocation::APPROVED, $location->fresh()->status);
        $this->assertSame('Sheikh Placeholder', $location->fresh()->reviewer->name);
    }

    public function test_taking_one_down_needs_a_reason(): void
    {
        $location = $this->sourcedLocation();
        $location->approve(Person::factory()->create());
        $location->fresh()->publish();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListZiyarahLocations::class)
            ->callTableAction('withdraw', $location->fresh(), ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertTrue($location->fresh()->isLive());
    }

    // ── What the table shows ─────────────────────────────────────────────

    public function test_the_source_count_is_on_the_row(): void
    {
        $sourced = $this->sourcedLocation();
        $bare = ZiyarahLocation::factory()->create();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListZiyarahLocations::class)
            ->assertOk()
            ->assertTableColumnStateSet('references_count', 1, $sourced)
            ->assertTableColumnStateSet('references_count', 0, $bare);
    }

    /** "Nobody yet" is a value, so the column's colour applies to it. */
    public function test_an_unreviewed_location_says_nobody_rather_than_showing_a_blank(): void
    {
        $location = $this->sourcedLocation();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListZiyarahLocations::class)
            ->assertTableColumnStateSet('reviewer.name', 'Nobody yet', $location);
    }

    /**
     * Whole sentences in the badge, not the raw column value.
     *
     * "approved" on a screen means "done" to the person reading it; what it
     * actually means here is "signed off and still not on the site".
     */
    public function test_the_status_badge_reads_as_a_sentence(): void
    {
        $location = $this->sourcedLocation();
        $location->approve(Person::factory()->create());

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ListZiyarahLocations::class)
            ->assertTableColumnStateSet('status', 'Approved, not yet on the site', $location->fresh());
    }

    // ── The relation managers ────────────────────────────────────────────

    /**
     * A relation manager loads in its own Livewire request, so the host
     * page's HTML contains no table at all — asserting on the edit page
     * would prove nothing about either of these.
     */
    public function test_the_corrections_table_shows_the_source_count(): void
    {
        $location = $this->sourcedLocation();

        $bare = LocationMisconception::factory()->create(['ziyarah_location_id' => $location->getKey()]);

        $sourced = LocationMisconception::factory()->create(['ziyarah_location_id' => $location->getKey()]);
        ArticleReference::factory()->create([
            'referenceable_type' => LocationMisconception::class,
            'referenceable_id' => $sourced->getKey(),
        ]);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(MisconceptionsRelationManager::class, [
                'ownerRecord' => $location,
                'pageClass' => EditZiyarahLocation::class,
            ])
            ->assertOk()
            ->assertTableColumnStateSet('references_count', '0', $bare)
            ->assertTableColumnStateSet('references_count', '1', $sourced);
    }

    /**
     * The sources relation manager is shared with the Knowledge Centre
     * rather than copied, so this proves it binds to a location too.
     */
    public function test_the_shared_sources_table_works_on_a_location(): void
    {
        $location = $this->sourcedLocation();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(ReferencesRelationManager::class, [
                'ownerRecord' => $location,
                'pageClass' => EditZiyarahLocation::class,
            ])
            ->assertOk()
            ->assertCanSeeTableRecords($location->references);
    }

    /**
     * A correction entered through the guarded form keeps both halves.
     *
     * Factories run unguarded, so a column missing from `$fillable` is set
     * happily in every other test here and dropped silently by a Filament
     * form. This is the only path that would catch that.
     */
    public function test_a_correction_written_through_the_form_keeps_both_halves(): void
    {
        $location = $this->sourcedLocation();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(MisconceptionsRelationManager::class, [
                'ownerRecord' => $location,
                'pageClass' => EditZiyarahLocation::class,
            ])
            ->callTableAction('create', data: [
                'belief' => ['en' => 'Placeholder belief through the form.'],
                'correction' => ['en' => 'Placeholder correction through the form.'],
                'sort_order' => 3,
                'references' => [],
            ])
            ->assertHasNoTableActionErrors();

        $written = $location->misconceptions()->latest('id')->first();

        $this->assertNotNull($written);
        $this->assertSame('Placeholder belief through the form.', $written->belief);
        $this->assertSame('Placeholder correction through the form.', $written->correction);
        $this->assertSame(3, $written->sort_order);
    }

    // ── Navigation ───────────────────────────────────────────────────────

    /**
     * The badge counts what somebody can act on. A count of locations says
     * only that somebody has been writing.
     */
    public function test_the_badge_counts_what_is_waiting_on_a_scholar(): void
    {
        $this->assertNull(ZiyarahLocationResource::getNavigationBadge());

        $location = $this->sourcedLocation();

        $this->assertNull(ZiyarahLocationResource::getNavigationBadge());

        $location->sendForReview();

        $this->assertSame('1', ZiyarahLocationResource::getNavigationBadge());
    }

    public function test_a_role_without_the_permission_cannot_open_the_list(): void
    {
        $this->actingAs($this->staff(Access::TOUR_LEADER))
            ->get(ZiyarahLocationResource::getUrl('index'))
            ->assertForbidden();
    }
}
