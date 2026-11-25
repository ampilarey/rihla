<?php

namespace Modules\Transport\Tests;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\Customer;
use App\Models\Island;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Support\Access;
use App\Support\Money;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Transport\Filament\Resources\TransportRequests\Pages\ManageTransportRequests;
use Modules\Transport\Http\Controllers\TransportRequestController;
use Modules\Transport\Models\TransportRequest;
use Modules\Transport\Services\TransportRequests;
use Tests\TestCase;

/**
 * Transport T4 (docs/TRANSPORT_PLAN.md): a cargo request goes from the form
 * to delivered with a paid quote, and a charter from the form to completed.
 */
class TransportRequestTest extends TestCase
{
    use RefreshDatabase;

    private Island $from;

    private Island $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        Services::save(['transport' => Services::COMING_SOON]);
        [$this->from, $this->to] = Island::query()->where('is_active', true)->orderBy('id')->limit(2)->get()->all();
    }

    /** @return array<string, mixed> */
    private function cargoForm(array $overrides = []): array
    {
        return $overrides + [
            'kind' => 'cargo',
            'origin_island_id' => $this->from->id,
            'destination_island_id' => $this->to->id,
            'wanted_on' => '2026-11-28',
            'cargo_description' => 'Two fridges and a washing machine, boxed.',
            'cargo_weight_kg' => 180,
            'contact_name' => 'Ibrahim Rasheed',
            'contact_phone' => '7781234',
        ];
    }

    private function staff(string $role = Access::BOOKING_STAFF): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_a_cargo_request_goes_from_the_form_to_delivered_with_a_paid_quote(): void
    {
        config(['payments.bank.accounts' => ['MVR' => '7730000123456']]);

        // The form, while the service is only coming soon: a request is an enquiry.
        $this->get('/en/boats/request')->assertOk()->assertSee('Send cargo or charter a boat');
        $response = $this->post('/en/boats/request', $this->cargoForm());

        $made = TransportRequest::query()->sole();
        $response->assertRedirect(TransportRequestController::statusUrl($made));
        $this->assertMatchesRegularExpression('/^RIH-R-2026-\d{5}$/', $made->reference);
        $this->assertSame(TransportRequest::REQUESTED, $made->status);
        $this->get(TransportRequestController::statusUrl($made))->assertOk()
            ->assertSee('We are working out a price and will message you.');

        // Staff quote it; the asker is queued to be told and their page shows the price.
        $desk = $this->staff();
        Livewire::actingAs($desk)->test(ManageTransportRequests::class)
            ->assertCanSeeTableRecords([$made])
            ->callTableAction('quote', $made, data: ['amount' => 1500, 'note' => 'Door to jetty, loaded by our crew.'])
            ->assertNotified('Quoted');

        $made->refresh();
        $this->assertSame(TransportRequest::QUOTED, $made->status);
        $this->assertSame(150000, $made->quote_minor);
        $notice = Notice::query()->where('kind', Notice::TRANSPORT_QUOTED)->sole();
        $this->assertSame('Ibrahim Rasheed', $notice->recipientName());
        $this->assertStringStartsWith('https://wa.me/7781234?text=', (string) $notice->whatsappUrl());
        $this->get(TransportRequestController::statusUrl($made))->assertOk()
            ->assertSee('MVR 1,500')->assertSee('7730000123456')->assertSee('Door to jetty');

        // Money recorded, then verified by Finance: only then is it paid.
        Livewire::actingAs($desk)->test(ManageTransportRequests::class)
            ->callTableAction('recordPayment', $made, data: ['method' => Payment::CASH, 'amount' => 1500, 'paid_at' => '2026-11-25'])
            ->assertNotified('Recorded MVR 1,500');
        $this->assertSame(TransportRequest::QUOTED, $made->fresh()->status, 'Recorded is not received.');

        app(Ledger::class)->reconcile(Payment::query()->sole(), null, $this->staff(Access::FINANCE));
        $this->assertSame(TransportRequest::PAID, $made->fresh()->status);

        // Received, loaded, delivered — one step at a time, and no further.
        foreach ([TransportRequest::RECEIVED, TransportRequest::LOADED, TransportRequest::DELIVERED] as $step) {
            Livewire::actingAs($desk)->test(ManageTransportRequests::class)->callTableAction('advance', $made->fresh());
            $this->assertSame($step, $made->fresh()->status);
        }

        $this->assertNull($made->fresh()->nextStep());
        $this->assertSame(
            ['requested', 'quoted', 'paid', 'received', 'loaded', 'delivered'],
            $made->events()->pluck('status')->all(),
        );
        $this->get(TransportRequestController::statusUrl($made))->assertSee('Delivered');
    }

    public function test_a_charter_is_completed_after_payment_and_nothing_moves_before_it(): void
    {
        $requests = app(TransportRequests::class);
        $made = $requests->submit([
            'kind' => 'charter', 'origin_island_id' => $this->from->id, 'destination_island_id' => $this->to->id,
            'wanted_on' => '2026-12-01', 'contact_name' => 'Aishath', 'contact_phone' => '7790000', 'passengers' => 12,
        ]);

        try {
            $requests->advance($made, null);
            $this->fail('A request moved on before it was paid.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('It has to be paid before it can move on.', $refusal->getMessage());
        }

        $requests->quote($made, Money::ofMajor(9000, 'MVR'), null, null);
        $made->storePaidTotal(400000);
        $this->assertSame(TransportRequest::QUOTED, $made->fresh()->status, 'A part payment is not paid.');
        $made->fresh()->storePaidTotal(900000);
        $this->assertSame(TransportRequest::PAID, $made->fresh()->status);

        $this->assertSame(TransportRequest::COMPLETED, $requests->advance($made->fresh(), null));
        $this->expectExceptionMessage('There is no step after completed.');
        $requests->advance($made->fresh(), null);
    }

    public function test_the_form_refuses_what_cannot_be_quoted_and_a_bot(): void
    {
        $this->from('/en/boats/request')->post('/en/boats/request', $this->cargoForm(['cargo_description' => '']))
            ->assertSessionHasErrors('cargo_description');
        $this->from('/en/boats/request')->post('/en/boats/request', $this->cargoForm(['destination_island_id' => $this->from->id]))
            ->assertSessionHasErrors('destination_island_id');
        $this->from('/en/boats/request')->post('/en/boats/request', $this->cargoForm(['kind' => 'charter', 'passengers' => null]))
            ->assertSessionHasErrors('passengers');
        $this->from('/en/boats/request')->post('/en/boats/request', $this->cargoForm(['wanted_on' => '2026-11-01']))
            ->assertSessionHasErrors('wanted_on');
        $this->post('/en/boats/request', $this->cargoForm(['website' => 'spam']))->assertRedirect();

        $this->assertSame(0, TransportRequest::count());
    }

    public function test_cancelling_needs_a_reason_and_no_money_and_the_status_page_needs_its_key(): void
    {
        $requests = app(TransportRequests::class);
        $made = $requests->submit($this->cargoForm() + []);

        $this->get('/en/boats/requests/'.$made->reference)->assertNotFound();
        $this->get('/en/boats/requests/'.str_repeat('a', 40))->assertNotFound();

        $requests->quote($made, Money::ofMajor(500, 'MVR'), null, null);
        $made->forceFill(['paid_minor' => 10000])->save();

        try {
            $requests->cancel($made, 'Changed my mind', null);
            $this->fail('A request with money on it was cancelled.');
        } catch (DeskRefusal $refusal) {
            $this->assertStringContainsString('refund the payment first', $refusal->getMessage());
        }

        $made->forceFill(['paid_minor' => 0])->save();
        Livewire::actingAs($this->staff())->test(ManageTransportRequests::class)
            ->callTableAction('cancel', $made, data: ['reason' => 'No boat free that day.']);
        $this->assertSame(TransportRequest::CANCELLED, $made->fresh()->status);
        $this->get(TransportRequestController::statusUrl($made))->assertSee('No boat free that day.');
    }

    public function test_switched_off_there_is_no_form_and_a_role_without_transport_sees_no_requests(): void
    {
        Services::save(['transport' => Services::OFF]);
        $this->get('/en/boats/request')->assertNotFound();

        $this->actingAs($this->staff(Access::TOUR_LEADER))->get('/staff/transport-requests')->assertForbidden();
        $this->actingAs($this->staff())->get('/staff/transport-requests')->assertOk();
    }

    public function test_the_quote_notice_is_on_the_queue_and_forgetting_reaches_the_request(): void
    {
        $customer = Customer::factory()->create();
        $made = app(TransportRequests::class)->submit([
            'kind' => 'cargo', 'origin_island_id' => $this->from->id, 'destination_island_id' => $this->to->id,
            'wanted_on' => '2026-11-28', 'contact_name' => 'Ibrahim Rasheed', 'contact_phone' => '7781234',
            'cargo_description' => 'A generator.',
        ], $customer);
        app(TransportRequests::class)->quote($made, Money::ofMajor(800, 'MVR'), 'Includes loading.', $this->staff());

        // With a customer on file, the queue names the customer.
        Livewire::actingAs($this->staff())->test(ListNotices::class)->call('loadTable')
            ->assertSee($customer->name)->assertSee('Cargo or charter quoted');

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $row = DB::table('transport_requests')->where('id', $made->id)->first();
        $this->assertNotSame('Ibrahim Rasheed', $row->contact_name);
        $this->assertNotSame('A generator.', $row->cargo_description);
        $this->assertStringNotContainsString('Includes loading', (string) DB::table('notices')->where('noticeable_id', $made->id)->value('body'));
        $this->assertStringNotContainsString('Includes loading', (string) DB::table('transport_request_events')->where('transport_request_id', $made->id)->where('status', 'quoted')->value('note'));
    }

    public function test_staff_take_a_request_for_somebody_on_the_phone(): void
    {
        Livewire::actingAs($this->staff())->test(ManageTransportRequests::class)
            ->callAction('new', data: [
                'kind' => 'charter', 'origin_island_id' => $this->from->id, 'destination_island_id' => $this->to->id,
                'wanted_on' => '2026-12-02', 'passengers' => 14, 'contact_name' => 'Hassan', 'contact_phone' => '7790001',
            ])
            ->assertNotified();

        $made = TransportRequest::query()->sole();
        $this->assertSame(TransportRequest::CHARTER, $made->kind);
        $this->assertSame(14, $made->passengers);
        $this->assertSame(TransportRequest::REQUESTED, $made->status);

    }
}
