<?php

namespace Tests\Feature;

use App\Exceptions\NotSoldToAudience;
use App\Exceptions\RoomNotAvailable;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\RelationManagers\RatesRelationManager;
use App\Filament\Resources\Properties\RelationManagers\RoomTypesRelationManager;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Stays\Availability;
use App\Services\Stays\Quote;
use App\Services\Stays\StayAllocator;
use App\Services\Stays\StayBooking;
use App\Support\Access;
use App\Support\Audience;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Tourists and locals on one calendar — §16.3 decision 6, §16 Phase 12.2.
 *
 * A guesthouse runs two rate cards: a tourist price in the property's own
 * currency and a local price in rufiyaa. Both sell the same rooms off the
 * same calendar. A room with no local price is not for sale to locals —
 * never free for them, and never quoted at the tourist price in the wrong
 * currency.
 */
class StayAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.currencies.local' => 'MVR', 'stays.green_tax.amount_minor' => 600]);

        $this->property = Property::factory()->create([
            'currency' => 'USD',
            'min_nights' => 1,
            'partner_id' => Partner::factory()->create(['green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY])->id,
        ]);

        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'quantity' => 1,
            'base_rate_minor' => 8000,
            'local_rate_minor' => 90000,
        ]);
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function quote(string $audience, ?RoomType $room = null): Quote
    {
        return app(Availability::class)->quote(
            ($room ?? $this->room)->fresh(),
            $this->date('2027-03-03'),
            $this->date('2027-03-05'),
            $audience,
        );
    }

    // ── Quoting ──────────────────────────────────────────────────────────

    /** Everything priced before §16 keeps its price: tourist is the default. */
    public function test_a_tourist_quote_is_the_rate_it_always_was(): void
    {
        $quote = app(Availability::class)->quote($this->room, $this->date('2027-03-03'), $this->date('2027-03-05'));

        $this->assertSame('USD', $quote->currency);
        $this->assertSame(16000, $quote->total()->minor);
        $this->assertSame(Audience::TOURIST, $quote->snapshot()['audience']);
    }

    public function test_a_local_is_quoted_the_local_rate_in_rufiyaa(): void
    {
        $quote = $this->quote(Audience::LOCAL);

        $this->assertSame('MVR', $quote->currency);
        $this->assertSame(180000, $quote->total()->minor);
        $this->assertSame(Audience::LOCAL, $quote->snapshot()['audience']);
    }

    public function test_a_room_with_no_local_price_is_not_sold_to_locals(): void
    {
        $this->room->update(['local_rate_minor' => null]);

        $this->assertFalse(app(Availability::class)->offers($this->room->fresh(), Audience::LOCAL));
        $this->assertTrue(app(Availability::class)->offers($this->room->fresh(), Audience::TOURIST));

        $this->expectException(NotSoldToAudience::class);
        $this->quote(Audience::LOCAL);
    }

    /** Each audience's seasons price only that audience. */
    public function test_a_season_prices_only_its_own_audience(): void
    {
        Rate::create(['room_type_id' => $this->room->id, 'audience' => Audience::LOCAL, 'starts_on' => '2027-03-01', 'ends_on' => '2027-03-31', 'rate_minor' => 120000]);
        Rate::create(['room_type_id' => $this->room->id, 'audience' => Audience::TOURIST, 'starts_on' => '2027-03-04', 'ends_on' => '2027-03-31', 'rate_minor' => 9500]);

        $this->assertSame(['2027-03-03' => 120000, '2027-03-04' => 120000], $this->quote(Audience::LOCAL)->nightly);
        $this->assertSame(['2027-03-03' => 8000, '2027-03-04' => 9500], $this->quote(Audience::TOURIST)->nightly);
    }

    /** A local season alone offers the room to locals, for the nights it covers only. */
    public function test_a_local_season_without_a_local_base_prices_only_what_it_covers(): void
    {
        $this->room->update(['local_rate_minor' => null]);
        Rate::create(['room_type_id' => $this->room->id, 'audience' => Audience::LOCAL, 'starts_on' => '2027-03-03', 'ends_on' => '2027-03-03', 'rate_minor' => 100000]);

        $this->assertTrue(app(Availability::class)->offers($this->room->fresh(), Audience::LOCAL));

        // The 4th has no local price, so the stay is not for sale.
        $this->expectException(NotSoldToAudience::class);
        $this->quote(Audience::LOCAL);
    }

    // ── Booking ──────────────────────────────────────────────────────────

    public function test_a_local_stay_is_frozen_in_rufiyaa_with_no_green_tax(): void
    {
        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $this->room,
            $this->date('2027-03-03'),
            $this->date('2027-03-05'),
            adults: 2,
            audience: Audience::LOCAL,
        );

        $this->assertSame(Audience::LOCAL, $stay->audience);
        $this->assertSame('MVR', $stay->currency);
        $this->assertSame(180000, $stay->total_minor);
        $this->assertSame(Audience::LOCAL, $stay->rate_snapshot['audience']);
        $this->assertFalse($stay->rate_snapshot['green_tax']['applies']);
        $this->assertNull($stay->rate_snapshot['green_tax']['total_minor']);
    }

    /** Whether locals owe it is the owner's rule, not this code's guess. */
    public function test_green_tax_reaches_a_local_stay_only_when_configured(): void
    {
        config(['stays.green_tax.applies_to_locals' => true]);

        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $this->room,
            $this->date('2027-03-03'),
            $this->date('2027-03-05'),
            adults: 2,
            audience: Audience::LOCAL,
        );

        $this->assertTrue($stay->rate_snapshot['green_tax']['applies']);
        $this->assertSame(600 * 2 * 2, $stay->rate_snapshot['green_tax']['total_minor']);
    }

    public function test_a_tourist_stay_still_owes_green_tax(): void
    {
        $stay = app(StayBooking::class)->request(
            Customer::factory()->create(),
            $this->room,
            $this->date('2027-03-03'),
            $this->date('2027-03-05'),
            adults: 2,
        );

        $this->assertSame(Audience::TOURIST, $stay->audience);
        $this->assertSame('USD', $stay->currency);
        $this->assertTrue($stay->rate_snapshot['green_tax']['applies']);
        $this->assertSame(2400, $stay->rate_snapshot['green_tax']['total_minor']);
    }

    /** One calendar: a local's held room is not sold again to a tourist. */
    public function test_both_audiences_share_one_calendar(): void
    {
        $booking = app(StayBooking::class);

        $local = $booking->request(Customer::factory()->create(), $this->room, $this->date('2027-03-03'), $this->date('2027-03-05'), audience: Audience::LOCAL);
        app(StayAllocator::class)->hold($local);

        $this->expectException(RoomNotAvailable::class);
        $booking->request(Customer::factory()->create(), $this->room->fresh(), $this->date('2027-03-04'), $this->date('2027-03-06'));
    }

    public function test_an_unknown_audience_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(StayBooking::class)->request(Customer::factory()->create(), $this->room, $this->date('2027-03-03'), $this->date('2027-03-05'), audience: 'resident');
    }

    public function test_nationality_decides_the_audience(): void
    {
        $this->assertSame(Audience::LOCAL, Audience::fromNationality('mv'));
        $this->assertSame(Audience::LOCAL, Audience::fromNationality('MDV'));
        $this->assertSame(Audience::TOURIST, Audience::fromNationality('GB'));
        $this->assertSame(Audience::TOURIST, Audience::fromNationality(null));
    }

    // ── The admin, through the guarded path ──────────────────────────────

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /** Factories run unguarded; the form does not — AGENTS.md. */
    public function test_the_local_rate_is_saved_from_the_room_form(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(RoomTypesRelationManager::class, ['ownerRecord' => $this->property, 'pageClass' => EditProperty::class])
            ->callTableAction('create', data: [
                'name' => ['en' => 'Garden Twin'],
                'sleeps' => 2,
                'quantity' => 2,
                'base_rate_minor' => 60,
                'local_rate_minor' => 700,
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $room = RoomType::where('property_id', $this->property->id)->whereJsonContains('name->en', 'Garden Twin')->sole();

        $this->assertSame(6000, $room->base_rate_minor);
        $this->assertSame(70000, $room->local_rate_minor);
    }

    /** Blank means "not offered to locals", never zero. */
    public function test_a_blank_local_rate_is_stored_as_not_offered(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(RoomTypesRelationManager::class, ['ownerRecord' => $this->property, 'pageClass' => EditProperty::class])
            ->callTableAction('create', data: [
                'name' => ['en' => 'Tourist only'],
                'sleeps' => 2,
                'quantity' => 1,
                'base_rate_minor' => 60,
                'local_rate_minor' => '',
                'sort_order' => 0,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertNull(RoomType::whereJsonContains('name->en', 'Tourist only')->sole()->local_rate_minor);
    }

    public function test_a_local_season_is_saved_with_its_audience(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(RatesRelationManager::class, ['ownerRecord' => $this->property, 'pageClass' => EditProperty::class])
            ->callTableAction('create', data: [
                'room_type_id' => $this->room->id,
                'audience' => Audience::LOCAL,
                'starts_on' => '2027-12-01',
                'ends_on' => '2027-12-31',
                'rate_minor' => 1500,
            ])
            ->assertHasNoTableActionErrors();

        $rate = Rate::sole();

        $this->assertSame(Audience::LOCAL, $rate->audience);
        $this->assertSame(150000, $rate->rate_minor);
    }

    /** A season written before §16 is a tourist season. */
    public function test_a_season_with_no_audience_is_a_tourist_one(): void
    {
        $rate = Rate::create(['room_type_id' => $this->room->id, 'starts_on' => '2027-03-01', 'ends_on' => '2027-03-31', 'rate_minor' => 5000]);

        $this->assertSame(Audience::TOURIST, $rate->fresh()->audience);
    }
}
