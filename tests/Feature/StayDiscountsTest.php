<?php

namespace Tests\Feature;

use App\Filament\Host\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Properties\RelationManagers\DiscountsRelationManager;
use App\Models\Customer;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\StayDiscount;
use App\Models\User;
use App\Services\Stays\Availability;
use App\Services\Stays\Commission;
use App\Services\Stays\StayBill;
use App\Services\Stays\StayBooking;
use App\Support\HostRole;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Promotions and long-stay discounts — §16 Phase 16.
 */
class StayDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $property;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-01-10'));
        Services::save(['stays_guesthouses' => Services::ON]);

        $this->host = Partner::factory()->commissionBased(15)->create();
        $this->property = Property::factory()->create(['partner_id' => $this->host->id, 'currency' => 'USD', 'min_nights' => 1]);
        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id, 'quantity' => 2, 'base_rate_minor' => 10000, 'local_rate_minor' => 90000,
            'name' => ['en' => 'Garden Double'],
        ]);
    }

    private function discount(array $attributes): StayDiscount
    {
        return StayDiscount::create($attributes + [
            'property_id' => $this->property->id, 'name' => 'Week-long stay', 'kind' => StayDiscount::LONG_STAY, 'percent' => 10, 'min_nights' => 7,
        ]);
    }

    private function quote(string $from, int $nights, string $audience = 'tourist', ?RoomType $room = null)
    {
        $checkIn = CarbonImmutable::parse($from);

        return app(Availability::class)->quote($room ?? $this->room, $checkIn, $checkIn->addDays($nights), $audience);
    }

    public function test_a_long_stay_is_discounted_from_its_night_and_not_before(): void
    {
        $this->discount([]);

        $week = $this->quote('2027-03-01', 7);
        $this->assertSame(70000, $week->subtotal()->minor);
        $this->assertSame(7000, $week->discountAmount()?->minor);
        $this->assertSame(63000, $week->total()->minor);
        $this->assertSame(['name' => 'Week-long stay', 'percent' => 10, 'minor' => 7000], $week->snapshot()['discount']);

        $this->assertNull($this->quote('2027-03-01', 6)->discount);
        $this->assertSame(60000, $this->quote('2027-03-01', 6)->total()->minor);
    }

    public function test_a_promotion_covers_check_ins_inside_its_window(): void
    {
        $this->discount(['kind' => StayDiscount::PROMOTION, 'name' => 'Quiet June', 'percent' => 20, 'min_nights' => null, 'starts_on' => '2027-06-01', 'ends_on' => '2027-06-30']);

        $this->assertSame(16000, $this->quote('2027-06-30', 2)->total()->minor);
        $this->assertNull($this->quote('2027-07-01', 2)->discount);
        $this->assertNull($this->quote('2027-05-31', 2)->discount);
    }

    /** Never two added together: the largest the stay qualifies for. */
    public function test_only_the_largest_discount_applies(): void
    {
        $this->discount([]);
        $this->discount(['name' => 'Fortnight', 'percent' => 15, 'min_nights' => 7]);
        $this->discount(['name' => 'Off', 'percent' => 40, 'is_active' => false]);

        $quote = $this->quote('2027-03-01', 7);

        $this->assertSame('Fortnight', $quote->discount['name']);
        $this->assertSame(59500, $quote->total()->minor);
    }

    public function test_a_discount_keeps_to_its_room_and_its_guests(): void
    {
        $other = RoomType::factory()->create(['property_id' => $this->property->id, 'base_rate_minor' => 10000, 'local_rate_minor' => 90000]);
        $this->discount(['room_type_id' => $this->room->id, 'audience' => 'local']);

        $this->assertNull($this->quote('2027-03-01', 7, 'tourist')->discount, 'Visitors are not offered it.');
        $this->assertNull($this->quote('2027-03-01', 7, 'local', $other)->discount, 'Another room is not.');
        $this->assertSame(567000, $this->quote('2027-03-01', 7, 'local')->total()->minor);
    }

    public function test_a_mistyped_percentage_never_gives_more_than_half_away(): void
    {
        $this->discount(['percent' => 90]);

        $this->assertSame(35000, $this->quote('2027-03-01', 7)->total()->minor);
    }

    public function test_the_amount_off_is_rounded_down(): void
    {
        $this->room->update(['base_rate_minor' => 3333]);
        $this->discount(['percent' => 10]);

        // 7 × 33.33 = 233.31; 10% is 23.331 → 23.33 off, not 23.34.
        $this->assertSame(2333, $this->quote('2027-03-01', 7)->discountAmount()?->minor);
    }

    public function test_a_booked_stay_keeps_its_discount_after_the_promotion_ends(): void
    {
        $discount = $this->discount([]);
        $checkIn = CarbonImmutable::parse('2027-03-01');

        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(), $this->room, $checkIn, $checkIn->addDays(7),
            adults: 2, details: ['source' => Commission::MARKETPLACE], audience: 'tourist',
        );

        $this->assertSame(63000, $stay->total_minor);
        $this->assertSame(9450, $stay->commission_minor, 'Commission is on what the guest pays.');
        $this->assertSame(7000, $stay->rate_snapshot['discount']['minor']);

        $discount->delete();

        $this->assertSame(63000, $stay->fresh()->total_minor);
        $this->assertStringContainsString('Week-long stay, 10% off', StayBill::for($stay->fresh())->lines()[0]['description']);
    }

    public function test_the_listing_says_what_is_included(): void
    {
        $this->discount([]);

        $this->get(route('stays.show', ['locale' => 'en', 'property' => $this->property->slug, 'from' => '2027-03-01', 'to' => '2027-03-08', 'guests' => 2]))
            ->assertOk()
            ->assertSee('USD 630')
            ->assertSee('Includes Week-long stay — 10% off');
    }

    // ── Who manages them ─────────────────────────────────────────────────

    private function member(string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    public function test_a_host_adds_a_discount_and_reception_cannot_touch_one(): void
    {
        $owner = $this->member(HostRole::OWNER);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);

        Livewire::actingAs($owner)
            ->test(DiscountsRelationManager::class, ['ownerRecord' => $this->property, 'pageClass' => EditListing::class])
            ->callTableAction('create', data: ['name' => 'Ten nights', 'kind' => StayDiscount::LONG_STAY, 'percent' => 12, 'min_nights' => 10, 'audience' => 'both', 'is_active' => true])
            ->assertHasNoTableActionErrors();

        $discount = StayDiscount::sole();
        $this->assertSame(12, $discount->percent);
        $this->assertTrue($owner->can('update', $discount));

        $reception = $this->member(HostRole::RECEPTION);
        $this->assertFalse($reception->can('update', $discount));
    }

    public function test_a_long_stay_needs_its_nights_and_a_percentage_has_a_ceiling(): void
    {
        $owner = $this->member(HostRole::OWNER);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);

        Livewire::actingAs($owner)
            ->test(DiscountsRelationManager::class, ['ownerRecord' => $this->property, 'pageClass' => EditListing::class])
            ->callTableAction('create', data: ['name' => 'Oops', 'kind' => StayDiscount::LONG_STAY, 'percent' => 75, 'min_nights' => null, 'audience' => 'both'])
            ->assertHasTableActionErrors(['percent', 'min_nights']);
    }
}
