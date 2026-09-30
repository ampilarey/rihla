<?php

namespace Tests\Feature;

use App\Filament\Host\Resources\Listings\ListingResource;
use App\Filament\Host\Resources\Listings\Pages\CreateListing;
use App\Filament\Host\Resources\Listings\Pages\EditListing;
use App\Filament\Host\Resources\Listings\Pages\ListListings;
use App\Filament\Resources\Properties\Pages\ListProperties;
use App\Filament\Resources\Properties\RelationManagers\BlockedDatesRelationManager;
use App\Filament\Resources\Properties\RelationManagers\RoomTypesRelationManager;
use App\Models\BlockedDate;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Access;
use App\Support\HostRole;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A host's own listings — §16.6, §16 Phase 14.2.
 */
class HostListingsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Partner $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->create(['name' => 'Coral Garden Inn']);
        $this->other = Partner::factory()->create(['name' => 'Somebody Else']);
        $this->owner = $this->member($this->host, HostRole::OWNER);
    }

    private function member(Partner $host, string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    /** Stand inside host A's panel, as a request to /host/{slug}/… would. */
    private function inPanel(Partner $host, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        // A real request boots the panel, which is what registers the
        // tenant scope and the ownership hook on the resource's model.
        // A Livewire test never makes that request, so it is done here —
        // without it every assertion below would pass or fail about an
        // unscoped query.
        Filament::getPanel('host')->boot();
        Filament::setTenant($host);
    }

    // ── The tenant scope ─────────────────────────────────────────────────

    public function test_a_host_sees_their_own_listings_and_nobody_elses(): void
    {
        $mine = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'My Place']]);
        $theirs = Property::factory()->create(['partner_id' => $this->other->id, 'name' => ['en' => 'Their Place']]);

        $this->inPanel($this->host);

        Livewire::actingAs($this->owner)
            ->test(ListListings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    /** Asked for by id — by URL and by component — another host's listing is not there. */
    public function test_another_hosts_listing_cannot_be_opened_by_id(): void
    {
        $theirs = Property::factory()->create(['partner_id' => $this->other->id]);

        $this->actingAs($this->owner)
            ->get('/host/'.$this->host->slug.'/listings/'.$theirs->slug.'/edit')
            ->assertNotFound();

        $this->inPanel($this->host);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->owner)->test(EditListing::class, ['record' => $theirs->getRouteKey()]);
    }

    /** Even a record that reached the policy by some other route is refused. */
    public function test_the_policy_refuses_another_hosts_listing_on_its_own(): void
    {
        $theirs = Property::factory()->create(['partner_id' => $this->other->id]);
        $mine = Property::factory()->create(['partner_id' => $this->host->id]);

        $this->inPanel($this->host);

        $this->assertTrue($this->owner->can('update', $mine));
        $this->assertFalse($this->owner->can('update', $theirs));
        $this->assertFalse($this->owner->can('delete', $mine), 'Removing a listing is Rihla\'s decision.');
    }

    public function test_reception_does_not_edit_listings(): void
    {
        $reception = $this->member($this->host, HostRole::RECEPTION);

        $this->actingAs($reception)->get('/host/'.$this->host->slug.'/listings')->assertForbidden();
    }

    // ── Making and submitting ────────────────────────────────────────────

    public function test_a_new_listing_is_the_hosts_and_starts_as_a_draft(): void
    {
        $this->inPanel($this->host);

        Livewire::actingAs($this->owner)
            ->test(CreateListing::class)
            ->fillForm([
                'type' => Property::GUESTHOUSE,
                'slug' => 'coral-garden-inn',
                'name' => ['en' => 'Coral Garden Inn'],
                'summary' => ['en' => 'Two minutes from the harbour.'],
                'currency' => 'USD',
                'deposit_pct' => 30,
                'balance_days_before' => 14,
                'free_cancel_days' => 14,
                'kind' => Property::KIND_GUESTHOUSE,
                'atoll' => 'Kaafu',
                'latitude' => '3.9412',
                'longitude' => '73.4903',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $listing = Property::where('slug', 'coral-garden-inn')->sole();

        $this->assertSame($this->host->id, $listing->partner_id);
        $this->assertSame(Property::DRAFT, $listing->approval);
        $this->assertSame('Kaafu', $listing->atoll);
        $this->assertFalse($listing->isListable(), 'A draft is not shown to anybody.');
    }

    public function test_a_host_submits_and_rihla_approves_or_asks_for_changes(): void
    {
        $listing = Property::factory()->create(['partner_id' => $this->host->id]);
        $listing->forceFill(['approval' => Property::DRAFT])->save();

        $this->inPanel($this->host);

        Livewire::actingAs($this->owner)
            ->test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->callAction('submitForApproval');

        $this->assertSame(Property::PENDING, $listing->fresh()->approval);
        $this->assertNotNull($listing->fresh()->submitted_at);

        // Now Rihla's side.
        Filament::setCurrentPanel(Filament::getPanel('staff'));
        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);

        Livewire::actingAs($staff)
            ->test(ListProperties::class)
            ->callAction(TestAction::make('requestChanges')->table($listing), data: ['note' => 'Add a photo of the bathroom.']);

        $this->assertSame(Property::CHANGES_REQUESTED, $listing->fresh()->approval);

        // The host reads the note on their own screen.
        $this->inPanel($this->host);
        $this->assertStringContainsString(
            'Add a photo of the bathroom.',
            (string) Livewire::actingAs($this->owner)->test(EditListing::class, ['record' => $listing->getRouteKey()])->instance()->getSubheading(),
        );

        Filament::setCurrentPanel(Filament::getPanel('staff'));

        Livewire::actingAs($staff)
            ->test(ListProperties::class)
            ->callAction(TestAction::make('approveListing')->table($listing->fresh()));

        $this->assertSame(Property::APPROVED, $listing->fresh()->approval);
        $this->assertSame($staff->id, $listing->fresh()->approved_by);
    }

    /** Approval is not a field a host can post. */
    public function test_a_host_cannot_approve_their_own_listing_through_the_form(): void
    {
        $listing = Property::factory()->create(['partner_id' => $this->host->id]);
        $listing->forceFill(['approval' => Property::DRAFT])->save();

        $this->inPanel($this->host);

        Livewire::actingAs($this->owner)
            ->test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['approval' => Property::APPROVED])
            ->call('save');

        $this->assertSame(Property::DRAFT, $listing->fresh()->approval);
    }

    // ── Rooms through the shared relation manager ────────────────────────

    public function test_a_host_adds_a_room_to_their_listing(): void
    {
        $listing = Property::factory()->create(['partner_id' => $this->host->id]);

        $this->inPanel($this->host);

        Livewire::actingAs($this->owner)
            ->test(RoomTypesRelationManager::class, ['ownerRecord' => $listing, 'pageClass' => EditListing::class])
            ->callTableAction('create', data: [
                'name' => ['en' => 'Garden Double'],
                'sleeps' => 2,
                'quantity' => 3,
                'base_rate_minor' => 85,
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(8500, RoomType::where('property_id', $listing->id)->sole()->base_rate_minor);
    }

    /**
     * A night a host blocks is recorded as theirs, whatever the request says.
     * The form offers "Rihla" and "an imported calendar" to staff; inside
     * /host neither is the host's to claim — security review of §16.
     */
    public function test_a_host_blocks_a_night_as_themselves(): void
    {
        $listing = Property::factory()->create(['partner_id' => $this->host->id]);
        $room = RoomType::factory()->create(['property_id' => $listing->id]);

        $this->inPanel($this->host);

        Livewire::actingAs($this->owner)
            ->test(BlockedDatesRelationManager::class, ['ownerRecord' => $listing, 'pageClass' => EditListing::class])
            ->callTableAction('create', data: [
                'room_type_id' => $room->id,
                'date' => '2027-12-24',
                'source' => BlockedDate::ADMIN,
                'note' => 'Family using it.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(BlockedDate::PARTNER, BlockedDate::where('room_type_id', $room->id)->sole()->source);
    }

    /** Outside /host, the shared policies answer exactly as before. */
    public function test_staff_permissions_are_unchanged_outside_the_host_panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('staff'));
        $listing = Property::factory()->create(['partner_id' => $this->host->id]);

        $this->assertFalse($this->owner->can('update', $listing), 'A host user holds no staff permission.');
        $this->assertTrue(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER)->can('update', $listing));
        $this->assertSame('listings', ListingResource::getSlug());
    }
}
