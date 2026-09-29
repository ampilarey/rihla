<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Host\Resources\Bookings\Pages\ListBookings;
use App\Filament\Host\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Stays\Schemas\StayDetails;
use App\Filament\Resources\Stays\StayResource;
use App\Models\Customer;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Services\Stays\Availability;
use App\Services\Stays\Commission;
use App\Services\Stays\StayBill;
use App\Services\Stays\StayBooking;
use App\Services\Stays\StayDesk;
use App\Support\Access;
use App\Support\Audience;
use App\Support\HostRole;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A host's bookings, at the desk — §16.6, §16.9, §16.10, §16 Phase 14.3.
 */
class HostBookingsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Partner $other;

    private User $owner;

    private Property $property;

    private RoomType $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2027, 1, 10)->startOfDay());

        $this->host = Partner::factory()->commissionBased(15)->create([
            'name' => 'Coral Garden Inn',
            'registration_number' => 'MOT-GH-2024-118',
        ]);
        $this->other = Partner::factory()->create();
        $this->owner = $this->member($this->host, HostRole::OWNER);

        $this->property = Property::factory()->create([
            'partner_id' => $this->host->id,
            'currency' => 'USD',
            'min_nights' => 1,
            'deposit_pct' => 30,
        ]);
        $this->room = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'quantity' => 2,
            'sleeps' => 2,
            'base_rate_minor' => 10000,
        ]);
    }

    private function member(Partner $host, string $role): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function inPanel(?User $as = null, ?Partner $host = null): void
    {
        $this->actingAs($as ?? $this->owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($host ?? $this->host);
    }

    /** @param array<string, mixed> $attributes */
    private function stay(string $status = Stay::CONFIRMED, array $attributes = []): Stay
    {
        return Stay::factory()->create($attributes + [
            'property_id' => $this->property->id,
            'room_type_id' => $this->room->id,
            'status' => $status,
            'check_in' => '2027-02-01',
            'check_out' => '2027-02-03',
            'nights' => 2,
            'total_minor' => 20000,
            'deposit_minor' => 3000,
        ]);
    }

    private function booking(Stay $stay, ?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->owner)->test(ViewBooking::class, ['record' => $stay->getRouteKey()]);
    }

    // ── The tenant scope ─────────────────────────────────────────────────

    public function test_a_host_sees_their_own_bookings_and_nobody_elses(): void
    {
        $mine = $this->stay();
        $theirs = Stay::factory()->create([
            'property_id' => ($p = Property::factory()->create(['partner_id' => $this->other->id]))->id,
            'room_type_id' => RoomType::factory()->create(['property_id' => $p->id])->id,
        ]);

        $this->inPanel();

        Livewire::actingAs($this->owner)
            ->test(ListBookings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    /** Asked for by reference — by URL and by component — another host's stay is not there. */
    public function test_another_hosts_booking_cannot_be_opened(): void
    {
        $p = Property::factory()->create(['partner_id' => $this->other->id]);
        $theirs = Stay::factory()->create([
            'property_id' => $p->id,
            'room_type_id' => RoomType::factory()->create(['property_id' => $p->id])->id,
        ]);

        $this->actingAs($this->owner)
            ->get('/host/'.$this->host->slug.'/bookings/'.$theirs->reference)
            ->assertNotFound();

        $this->inPanel();

        $this->expectException(ModelNotFoundException::class);
        $this->booking($theirs);
    }

    public function test_reception_runs_the_desk(): void
    {
        $reception = $this->member($this->host, HostRole::RECEPTION);
        $stay = $this->stay();

        $this->inPanel($reception);

        $this->assertTrue($reception->can('update', $stay));
        $this->assertFalse($reception->can('delete', $stay), 'Nobody deletes a stay.');
        $this->actingAs($reception)->get('/host/'.$this->host->slug.'/bookings')->assertOk();
        $this->actingAs($reception)->get('/host/'.$this->host->slug.'/bookings/'.$stay->reference)->assertOk();
    }

    /** The staff board shows the same stay, with the bill and whose hands the money is in. */
    public function test_the_staff_view_shows_the_bill_and_the_split(): void
    {
        $stay = $this->stay(Stay::CHECKED_IN);
        app(StayDesk::class)->recordHostPayment($stay, $this->host, Money::ofMajor(50, 'USD'), Payment::CASH);

        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);

        $this->actingAs($staff)->get(StayResource::getUrl('view', ['record' => $stay]))
            ->assertOk()
            ->assertSee('Paid at the property')
            ->assertSee('USD 50')
            ->assertSee('Balance at the desk');
    }

    // ── Yes and no ───────────────────────────────────────────────────────

    public function test_accepting_holds_the_nights(): void
    {
        $stay = $this->stay(Stay::REQUESTED);

        $this->inPanel();
        $this->booking($stay)->callAction('accept');

        $this->assertSame(Stay::HELD, $stay->fresh()->status);
        $this->assertNotNull($stay->fresh()->expires_at);
    }

    public function test_accepting_nights_that_have_gone_says_so(): void
    {
        $this->room->update(['quantity' => 1]);
        $this->stay(Stay::CONFIRMED);
        $asked = $this->stay(Stay::REQUESTED);

        $this->inPanel();
        $this->booking($asked)->callAction('accept')->assertNotified('Those nights have gone');

        $this->assertSame(Stay::REQUESTED, $asked->fresh()->status);
    }

    public function test_declining_needs_a_reason_the_guest_reads(): void
    {
        $stay = $this->stay(Stay::REQUESTED);

        $this->inPanel();
        $this->booking($stay)->callAction('decline', data: ['reason' => null])->assertHasActionErrors(['reason' => 'required']);
        $this->booking($stay)->callAction('decline', data: ['reason' => 'We are full that week.']);

        $this->assertSame(Stay::DECLINED, $stay->fresh()->status);
        $this->assertSame('We are full that week.', $stay->fresh()->cancellation_reason);
    }

    // ── Check-in and the register ────────────────────────────────────────

    public function test_check_in_puts_them_in_a_room_and_writes_the_register(): void
    {
        $stay = $this->stay();
        $unit = PropertyUnit::factory()->create(['room_type_id' => $this->room->id, 'label' => 'Room 4']);

        $this->inPanel();
        $this->booking($stay)->callAction('checkIn', data: [
            'unit_id' => $unit->id,
            'guests' => [
                ['full_name' => 'Aminath Guest', 'nationality' => 'German', 'id_type' => 'passport', 'id_number' => 'C01X00T47', 'is_lead' => true],
                ['full_name' => 'Second Guest', 'nationality' => 'German', 'id_type' => 'passport', 'id_number' => 'C01X00T48', 'is_lead' => false],
            ],
        ])->assertNotified('Checked in');

        $stay->refresh();
        $this->assertSame(Stay::CHECKED_IN, $stay->status);
        $this->assertSame($unit->id, $stay->unit_id);
        $this->assertNotNull($stay->checked_in_at);
        $this->assertSame(2, $stay->guests()->count());
        $this->assertSame('Aminath Guest', $stay->guests()->lead()->sole()->full_name);

        // The identifier is encrypted in the column, readable through the model.
        $raw = DB::table('stay_guests')->where('full_name', 'Aminath Guest')->value('id_number');
        $this->assertStringNotContainsString('C01X00T47', (string) $raw);
        $this->assertSame('C01X00T47', $stay->guests()->lead()->sole()->id_number);
    }

    public function test_the_register_needs_exactly_one_lead_guest(): void
    {
        $stay = $this->stay();

        $this->expectException(DeskRefusal::class);
        app(StayDesk::class)->checkIn($stay, [['full_name' => 'Nobody In Charge', 'is_lead' => false]]);
    }

    public function test_a_room_of_another_kind_or_with_somebody_in_it_is_refused(): void
    {
        $otherKind = PropertyUnit::factory()->create([
            'room_type_id' => RoomType::factory()->create(['property_id' => $this->property->id])->id,
        ]);
        $unit = PropertyUnit::factory()->create(['room_type_id' => $this->room->id]);
        $this->stay(Stay::CHECKED_IN, ['unit_id' => $unit->id]);
        $stay = $this->stay();
        $lead = [['full_name' => 'A Guest', 'is_lead' => true]];

        try {
            app(StayDesk::class)->checkIn($stay, $lead, $otherKind);
            $this->fail('A room of another kind was accepted.');
        } catch (DeskRefusal $refusal) {
            $this->assertStringContainsString('not one of the kind', $refusal->getMessage());
        }

        try {
            app(StayDesk::class)->checkIn($stay, $lead, $unit);
            $this->fail('An occupied room was accepted.');
        } catch (DeskRefusal $refusal) {
            $this->assertStringContainsString('has somebody in it', $refusal->getMessage());
        }

        $this->assertSame(Stay::CONFIRMED, $stay->fresh()->status);
        $this->assertSame(0, $stay->guests()->count(), 'A refused check-in writes nothing.');
    }

    /** Shown, never acted on: the platform does not re-price a stay behind the guest's back. */
    public function test_a_lead_guest_who_does_not_match_the_price_is_pointed_out(): void
    {
        $stay = $this->stay(Stay::CONFIRMED, ['audience' => Audience::TOURIST]);

        app(StayDesk::class)->checkIn($stay, [['full_name' => 'Ali Local', 'nationality' => 'Maldivian', 'is_lead' => true]]);

        $this->assertStringContainsString('priced for a visitor', (string) app(StayDesk::class)->audienceMismatch($stay->fresh()));
        $this->assertSame(20000, $stay->fresh()->total_minor);
    }

    public function test_check_out_closes_the_stay_and_sends_the_room_to_housekeeping(): void
    {
        $unit = PropertyUnit::factory()->create(['room_type_id' => $this->room->id]);
        $stay = $this->stay(Stay::CHECKED_IN, ['unit_id' => $unit->id]);

        $this->inPanel();
        $this->booking($stay)->callAction('checkOut');

        $this->assertSame(Stay::COMPLETED, $stay->fresh()->status);
        $this->assertNotNull($stay->fresh()->checked_out_at);
        $this->assertSame(PropertyUnit::DIRTY, $unit->fresh()->housekeeping);
    }

    // ── The bill ─────────────────────────────────────────────────────────

    public function test_the_bill_is_the_room_plus_what_the_host_added_less_what_was_paid(): void
    {
        $stay = $this->stay(Stay::CHECKED_IN);

        $this->inPanel();
        $this->booking($stay)->callAction('addCharge', data: ['kind' => StayCharge::EXTRA, 'description' => 'Sandbank trip', 'quantity' => 2, 'each' => 20]);
        $this->booking($stay)->callAction('addCharge', data: ['kind' => StayCharge::DISCOUNT, 'description' => 'Returning guest', 'quantity' => 1, 'each' => 10]);
        $this->booking($stay)->callAction('recordPayment', data: ['amount' => 100, 'method' => Payment::CASH]);

        $bill = StayBill::for($stay->fresh());

        $this->assertSame(20000 + 4000 - 1000, $bill->total()->minor);
        $this->assertSame(23000 - 10000, $bill->balance()->minor);
        $this->assertSame(-1000, StayCharge::where('kind', StayCharge::DISCOUNT)->sole()->total_minor, 'A discount is stored negative whatever was typed.');
    }

    public function test_green_tax_paid_at_the_property_is_on_the_bill_in_its_own_currency_only(): void
    {
        $stay = $this->stay(Stay::CONFIRMED, ['rate_snapshot' => ['green_tax' => [
            'applies' => true, 'mode' => Partner::GREEN_TAX_AT_PROPERTY,
            'guests' => 2, 'nights' => 2, 'per_guest_per_night_minor' => 600, 'currency' => 'USD', 'total_minor' => 2400,
        ]]]);

        $this->assertSame(22400, StayBill::for($stay)->total()->minor);

        // A stay in rufiyaa: the tax is said, never summed across currencies.
        $mvr = $this->stay(Stay::CONFIRMED, ['currency' => 'MVR', 'rate_snapshot' => $stay->rate_snapshot]);
        $this->assertSame(20000, StayBill::for($mvr)->total()->minor);
        $this->assertStringContainsString('USD 24', StayBill::for($mvr)->notes()[0]);
    }

    public function test_the_bill_prints_in_the_hosts_name(): void
    {
        $stay = $this->stay(Stay::CHECKED_IN);

        $this->inPanel();
        $this->booking($stay)->callAction('printBill')->assertFileDownloaded('bill-'.$stay->reference.'.pdf');

        $html = view('pdf.stay-bill', [
            'stay' => $stay->load(['customer', 'property', 'payments']),
            'bill' => StayBill::for($stay),
            'issuer' => ['name' => 'Coral Garden Inn', 'address' => null, 'registration' => 'MOT-GH-2024-118', 'phone' => '', 'email' => null],
            'footer' => 'Issued through Rihla Travels.',
        ])->render();

        $this->assertStringContainsString('Coral Garden Inn', $html);
        $this->assertStringContainsString('MOT-GH-2024-118', $html);
        $this->assertStringContainsString('Issued through Rihla Travels.', $html);
        $this->assertStringStartsWith('%PDF', StayBill::for($stay)->pdf());
    }

    // ── Money at the property — §16.9 ────────────────────────────────────

    /**
     * The rule the plan says to plant: a host recording cash cannot confirm
     * a marketplace stay whose booking deposit never reached Rihla.
     */
    public function test_cash_at_the_desk_does_not_confirm_a_stay_waiting_for_its_deposit(): void
    {
        $stay = $this->stay(Stay::HELD, ['source' => Commission::MARKETPLACE, 'expires_at' => now()->addDay()]);

        $this->inPanel();
        $this->booking($stay)->callAction('recordPayment', data: ['amount' => 200, 'method' => Payment::CASH, 'note' => 'Paid in full on arrival']);

        $payment = Payment::sole();
        $this->assertSame(Payment::COLLECTED_BY_HOST, $payment->collected_by);
        $this->assertSame(Payment::SUCCEEDED, $payment->status);
        $this->assertSame($this->host->id, $payment->partner_id);
        $this->assertSame($this->owner->id, $payment->recorded_by);

        $stay->refresh();
        $this->assertSame(20000, $stay->paid_minor, 'It is the guest\'s money: it counts towards what they paid.');
        $this->assertSame(0, $stay->paidToRihla()->minor);
        $this->assertFalse($stay->depositIsPaid());

        // Even pushed through the path that confirms on a paid deposit.
        app(StayBooking::class)->settle(Payment::factory()->create([
            'payable_type' => Stay::class, 'payable_id' => $stay->id, 'currency' => 'USD', 'amount_minor' => 1,
        ]));
        $this->assertSame(Stay::HELD, $stay->fresh()->status);
    }

    public function test_rihlas_deposit_still_confirms(): void
    {
        $stay = $this->stay(Stay::HELD, ['expires_at' => now()->addDay()]);

        app(StayBooking::class)->settle(Payment::factory()->create([
            'payable_type' => Stay::class, 'payable_id' => $stay->id, 'currency' => 'USD', 'amount_minor' => 3000,
        ]));

        $this->assertSame(Stay::CONFIRMED, $stay->fresh()->status);
        $this->assertSame(3000, $stay->fresh()->paidToRihla()->minor);
    }

    public function test_a_host_refunds_only_what_they_took_and_rihla_never_refunds_it(): void
    {
        $stay = $this->stay(Stay::CHECKED_IN);
        $desk = app(StayDesk::class);

        $cash = $desk->recordHostPayment($stay, $this->host, Money::ofMajor(50, 'USD'), Payment::CASH);

        try {
            $desk->recordHostPayment($stay, $this->host, Money::ofMajor(60, 'USD'), Payment::CASH, refund: true);
            $this->fail('A refund larger than what was taken here was accepted.');
        } catch (DeskRefusal) {
        }

        $desk->recordHostPayment($stay, $this->host, Money::ofMajor(20, 'USD'), Payment::CASH, refund: true);
        $this->assertSame(3000, $stay->fresh()->paidToHost()->minor);
        $this->assertSame(3000, $stay->fresh()->paid_minor);

        $this->expectException(\InvalidArgumentException::class);
        app(Ledger::class)->refund($cash);
    }

    /** §16.9: under `commission_deposit` the online payment is the commission. */
    public function test_a_marketplace_deposit_is_the_commission(): void
    {
        $booking = app(StayBooking::class);
        $customer = Customer::factory()->create();

        $stay = $booking->request($customer, $this->room, now()->addMonth(), now()->addMonth()->addDays(2), details: ['source' => Commission::MARKETPLACE]);
        $this->assertSame(3000, $stay->commission_minor);
        $this->assertSame(3000, $stay->deposit_minor);

        // A host on a net rate keeps the property's own deposit: zero could never be paid.
        $this->host->forceFill(['pricing_model' => Partner::NET_RATE, 'commission_pct' => null])->save();
        $net = $booking->request($customer, $this->room->fresh(), now()->addMonths(2), now()->addMonths(2)->addDays(2), details: ['source' => Commission::MARKETPLACE]);
        $this->assertSame(0, $net->commission_minor);
        $this->assertSame(6000, $net->deposit_minor);

        // A booking Rihla did not bring keeps the property's deposit too.
        $phone = $booking->request($customer, $this->room, now()->addMonths(3), now()->addMonths(3)->addDays(2), details: ['source' => 'phone']);
        $this->assertSame(6000, $phone->deposit_minor);
    }

    // ── Cancelling ───────────────────────────────────────────────────────

    public function test_cancelling_puts_the_nights_back(): void
    {
        $this->room->update(['quantity' => 1]);
        $stay = $this->stay();

        $this->inPanel();
        $this->booking($stay)->callAction('cancel', data: ['reason' => 'The roof is being repaired.']);

        $this->assertSame(Stay::CANCELLED, $stay->fresh()->status);
        $this->assertTrue(app(Availability::class)->isAvailable($this->room, $stay->check_in, $stay->check_out));
    }

    // ── The register, masked — §16.6 ─────────────────────────────────────

    public function test_reception_sees_identifiers_masked_and_the_owner_whole(): void
    {
        $stay = $this->stay();
        app(StayDesk::class)->checkIn($stay, [['full_name' => 'Aminath Guest', 'id_number' => 'C01X00T47', 'is_lead' => true]]);

        $reception = $this->member($this->host, HostRole::RECEPTION);

        $this->inPanel($reception);
        $this->booking($stay->fresh(), $reception)
            ->assertSee(StayDetails::mask('C01X00T47'))
            ->assertDontSee('C01X00T47');

        $this->inPanel();
        $this->booking($stay->fresh())->assertSee('C01X00T47');
    }
}
