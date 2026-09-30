<?php

namespace Tests\Feature;

use App\Filament\Host\Pages\Calendar;
use App\Filament\Host\Pages\Dashboard;
use App\Filament\Host\Resources\Bookings\Pages\ViewBooking;
use App\Models\Customer;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayGuest;
use App\Models\User;
use App\Support\HostRole;
use App\Support\LivewireReturns;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Nothing a Livewire method returns reaches the browser as a model — the
 * security review of §16.
 *
 * A reception member could call `$wire.host()` on the dashboard and read the
 * host's bank account, `$wire.rows()` on the calendar and read guests'
 * national IDs, and `$wire.getRecord()` on a booking and read passport
 * numbers the page itself masks for them.
 */
class LivewireReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Stay $stay;

    private User $reception;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->create();
        $this->host->forceFill([
            'payout_bank_name' => 'Bank of Maldives',
            'payout_account_name' => 'Coral Garden',
            'payout_account_number' => '7730000099887',
            'contract_notes' => 'Secret rate agreed with the owner',
        ])->save();

        $property = Property::factory()->create(['partner_id' => $this->host->id]);
        $room = RoomType::factory()->create(['property_id' => $property->id]);
        $customer = Customer::factory()->create(['national_id' => 'A123456', 'notes' => 'Private CRM note']);

        $this->stay = Stay::factory()->confirmed()->create([
            'customer_id' => $customer->id, 'property_id' => $property->id, 'room_type_id' => $room->id,
            'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(), 'nights' => 2,
        ]);
        StayGuest::create(['stay_id' => $this->stay->id, 'full_name' => 'Lead Guest', 'id_number' => 'P9999999', 'is_lead' => true]);

        $this->reception = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $this->reception->id, 'role' => HostRole::RECEPTION, 'accepted_at' => now()]);

        $this->actingAs($this->reception);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    private function assertNothingSecret(mixed $returns): void
    {
        $json = (string) json_encode($returns);

        foreach (['7730000099887', 'Secret rate', 'A123456', 'Private CRM note', 'P9999999'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "A call returned [{$secret}] to the browser.");
        }
    }

    public function test_the_dashboard_does_not_hand_out_the_host(): void
    {
        $component = Livewire::actingAs($this->reception)->test(Dashboard::class)->call('host');

        $this->assertNothingSecret($component->effects['returns'] ?? []);
    }

    public function test_the_calendar_does_not_hand_out_its_stays(): void
    {
        $component = Livewire::actingAs($this->reception)->test(Calendar::class)->call('rows');

        $this->assertNothingSecret($component->effects['returns'] ?? []);
    }

    public function test_a_booking_page_does_not_hand_out_its_record(): void
    {
        $component = Livewire::actingAs($this->reception)
            ->test(ViewBooking::class, ['record' => $this->stay->getRouteKey()])
            ->call('getRecord');

        $this->assertNothingSecret($component->effects['returns'] ?? []);
    }

    /** What a call legitimately returns still arrives — only models are withheld. */
    public function test_plain_values_still_come_back(): void
    {
        $this->assertSame([1, 'two', null, ['x' => null]], LivewireReturns::strip([1, 'two', $this->host, ['x' => $this->host]]));
        $this->assertFalse(LivewireReturns::forBrowser($this->host));
        $this->assertNull(LivewireReturns::forBrowser(null));
        $this->assertSame('ok', LivewireReturns::forBrowser('ok'));
    }
}
