<?php

namespace Tests\Feature;

use App\Filament\Host\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Properties\RelationManagers\AddonsRelationManager;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertyAddon;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Models\User;
use App\Services\Stays\StayBill;
use App\Support\HostRole;
use App\Support\Services;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A host's add-ons, picked at booking — §16.14, §16 Phase 15.
 */
class StayAddonsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $property;

    private RoomType $room;

    private PropertyAddon $transfer;

    private PropertyAddon $breakfast;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);
        RateLimiter::clear('stay-book');
        $this->travelTo(now()->setDate(2027, 1, 10));

        $this->host = Partner::factory()->commissionBased(15)->create();
        $this->property = Property::factory()->create([
            'currency' => 'USD', 'min_nights' => 1, 'deposit_pct' => 30, 'partner_id' => $this->host->id,
        ]);
        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id, 'quantity' => 1, 'sleeps' => 3, 'base_rate_minor' => 10000, 'local_rate_minor' => 90000,
        ]);

        $this->transfer = PropertyAddon::factory()->create([
            'property_id' => $this->property->id, 'name' => ['en' => 'Speedboat transfer'], 'price_minor' => 5000, 'local_price_minor' => null,
        ]);
        $this->breakfast = PropertyAddon::factory()->perPerson()->create([
            'property_id' => $this->property->id, 'name' => ['en' => 'Island breakfast'], 'price_minor' => 1200, 'local_price_minor' => 15000,
        ]);
    }

    private function book(array $overrides = []): TestResponse
    {
        return $this->post(route('stays.book.store', ['locale' => 'en', 'property' => $this->property->slug]), $overrides + [
            'room' => $this->room->id, 'from' => '2027-03-03', 'to' => '2027-03-05', 'adults' => 2, 'children' => 1,
            'audience' => 'tourist', 'name' => 'Sara Visitor', 'email' => 'sara@example.test', 'phone' => '+44 7700 900123',
            'citizenship' => 'other', 'accept' => '1',
        ]);
    }

    public function test_picked_add_ons_go_on_the_bill_and_leave_the_room_money_alone(): void
    {
        $this->book(['addons' => [$this->transfer->id, $this->breakfast->id]])->assertRedirect();

        $stay = Stay::sole();

        $this->assertSame(20000, $stay->total_minor, 'The room total is the room alone.');
        $this->assertSame(3000, $stay->commission_minor, 'Commission is on the room, not the extras.');
        $this->assertSame(3000, $stay->deposit()->minor, 'The deposit is the commission on the room (commission_deposit), untouched by extras.');

        $extras = $stay->charges()->where('kind', StayCharge::EXTRA)->orderBy('id')->get();
        $this->assertSame(['Speedboat transfer', 'Island breakfast'], $extras->pluck('description')->all());
        $this->assertSame([1, 3], $extras->pluck('quantity')->all(), 'Per stay once; per person for each of three guests.');
        $this->assertSame([5000, 3600], $extras->pluck('total_minor')->all());

        // On the bill the host prints.
        $bill = StayBill::for($stay);
        $this->assertSame(20000 + 5000 + 3600, $bill->total()->minor);
    }

    public function test_a_price_changed_later_changes_nobodys_booking(): void
    {
        $this->book(['addons' => [$this->transfer->id]]);
        $this->transfer->update(['price_minor' => 9900]);

        $this->assertSame(5000, StayCharge::where('kind', StayCharge::EXTRA)->sole()->total_minor);
    }

    /** An id from the request is a wish: another listing's, a withdrawn one, or one not sold to this guest is dropped. */
    public function test_only_this_listings_offered_add_ons_are_taken(): void
    {
        // Both priced for a local, so only the rule under test can drop them.
        $elsewhere = PropertyAddon::factory()->create(['name' => ['en' => 'Somebody else\'s dinner'], 'price_minor' => 100, 'local_price_minor' => 1000]);
        $withdrawn = PropertyAddon::factory()->create(['property_id' => $this->property->id, 'name' => ['en' => 'Withdrawn trip'], 'local_price_minor' => 1000, 'is_active' => false]);

        $this->book([
            'citizenship' => 'maldivian', 'audience' => 'local',
            'addons' => [$elsewhere->id, $withdrawn->id, $this->transfer->id, $this->breakfast->id],
        ])->assertRedirect();

        $extra = StayCharge::where('kind', StayCharge::EXTRA)->sole();
        $this->assertSame('Island breakfast', $extra->description, 'The transfer has no local price, so a local cannot add it.');
        $this->assertSame('MVR', $extra->currency);
        $this->assertSame(45000, $extra->total_minor);
    }

    public function test_the_pages_show_what_this_guest_can_add(): void
    {
        $query = ['room' => $this->room->id, 'from' => '2027-03-03', 'to' => '2027-03-05', 'adults' => 2];

        $this->get(route('stays.book', ['locale' => 'en', 'property' => $this->property->slug] + $query + ['audience' => 'tourist']))
            ->assertOk()
            ->assertSee('Speedboat transfer')
            ->assertSee('USD 24 for your party')
            ->assertSee('Paid to the host at the property, not now.');

        $this->get(route('stays.book', ['locale' => 'en', 'property' => $this->property->slug] + $query + ['audience' => 'local']))
            ->assertOk()
            ->assertDontSee('Speedboat transfer')
            ->assertSee('Island breakfast');

        $this->get(route('stays.show', ['locale' => 'en', 'property' => $this->property->slug]))
            ->assertOk()
            ->assertSee('Extras you can add')
            ->assertSee('Speedboat transfer');
    }

    // ── The host manages them ────────────────────────────────────────────

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    public function test_a_host_adds_an_add_on_in_whole_units(): void
    {
        $owner = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $owner->id, 'role' => HostRole::OWNER, 'accepted_at' => now()]);
        $this->inPanel($owner);

        Livewire::actingAs($owner)
            ->test(AddonsRelationManager::class, ['ownerRecord' => $this->property, 'pageClass' => EditListing::class])
            ->callTableAction('create', data: [
                'name' => ['en' => 'Sandbank picnic'],
                'pricing' => PropertyAddon::PER_PERSON,
                'price_minor' => 45,
                'local_price_minor' => null,
                'is_active' => true,
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $addon = PropertyAddon::where('property_id', $this->property->id)->latest('id')->first();
        $this->assertSame('Sandbank picnic', $addon->getTranslation('name', 'en'));
        $this->assertSame(4500, $addon->price_minor);
        $this->assertNull($addon->local_price_minor, 'Blank is not offered — never free.');
        $this->assertTrue($owner->can('update', $this->transfer), 'Their own listing\'s add-on.');
    }

    public function test_reception_and_other_hosts_cannot_touch_them(): void
    {
        $reception = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $reception->id, 'role' => HostRole::RECEPTION, 'accepted_at' => now()]);
        $this->inPanel($reception);
        $this->assertFalse($reception->can('update', $this->transfer));

        $other = Partner::factory()->create();
        $stranger = User::factory()->create();
        HostMembership::create(['partner_id' => $other->id, 'user_id' => $stranger->id, 'role' => HostRole::OWNER, 'accepted_at' => now()]);
        $this->actingAs($stranger);
        Filament::setTenant($other);
        $this->assertFalse($stranger->can('update', $this->transfer), 'Another host\'s add-on.');
    }
}
