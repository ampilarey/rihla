<?php

namespace Modules\Travel\Tests;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\Customer;
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
use Modules\Travel\Filament\Resources\TravelRequests\Pages\ManageTravelRequests;
use Modules\Travel\Http\Controllers\TravelController;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Services\TravelRequests;
use Tests\TestCase;

/**
 * Travel agency A1 (docs/TRAVEL_AGENCY_PLAN.md): a "plan my trip" request
 * is quoted with options and an expiry, the customer chooses one online,
 * and pays for it.
 */
class TravelRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        Services::save(['travel' => Services::COMING_SOON]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'wants' => ['flight', 'hotel'],
            'origin' => 'Malé',
            'destination' => 'Kuala Lumpur',
            'depart_on' => '2026-12-20',
            'return_on' => '2026-12-27',
            'adults' => 2,
            'children' => 1,
            'contact_name' => 'Mariyam Saeed',
            'contact_phone' => '7795555',
            'contact_email' => 'mariyam@example.com',
        ];
    }

    private function staff(string $role = Access::BOOKING_STAFF): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_a_request_is_quoted_chosen_online_and_paid(): void
    {
        config(['payments.bank.accounts' => ['MVR' => '7730000123456']]);

        $this->get('/en/travel')->assertOk()->assertSee('Plan my trip')->assertSee('opening our travel desk soon');
        $response = $this->post('/en/travel', $this->form());

        $made = TravelRequest::query()->sole();
        $response->assertRedirect(TravelController::statusUrl($made));
        $this->assertMatchesRegularExpression('/^RIH-V-2026-\d{5}$/', $made->reference);
        $this->assertSame('flight and hotel', $made->wants());
        $this->get(TravelController::statusUrl($made))->assertOk()->assertSee('We are finding options');

        // Staff add two options and send them for three days.
        $desk = $this->staff();
        Livewire::actingAs($desk)->test(ManageTravelRequests::class)
            ->assertCanSeeTableRecords([$made])
            ->assertTableActionHidden('sendQuote', $made)
            ->callTableAction('addOption', $made, data: ['title' => 'Malaysia Airlines direct, 3-star', 'details' => 'Bags included.', 'price' => 21000])
            ->callTableAction('addOption', $made, data: ['title' => 'AirAsia, 4-star near KLCC', 'price' => 18500])
            ->callTableAction('sendQuote', $made, data: ['days' => 3])
            ->assertNotified('Quote sent');

        $made->refresh();
        $this->assertSame(TravelRequest::QUOTED, $made->status);
        $this->assertSame('2026-11-28 23:59:59', $made->quote_expires_at?->format('Y-m-d H:i:s'));
        $notice = Notice::query()->where('kind', Notice::TRAVEL_QUOTED)->sole();
        $this->assertSame('Mariyam Saeed', $notice->recipientName());
        $this->assertSame($made->reference, $notice->subjectReference());

        // The customer chooses the cheaper one on their page.
        $cheaper = $made->options()->where('title', 'like', 'AirAsia%')->sole();
        $this->get(TravelController::statusUrl($made))->assertOk()
            ->assertSee('Malaysia Airlines direct')->assertSee('MVR 18,500')->assertSee('Choose this option');
        $this->post(route('travel.requests.accept', ['locale' => 'en', 'key' => $made->status_key]), ['option' => $cheaper->id])
            ->assertRedirect(TravelController::statusUrl($made));

        $made->refresh();
        $this->assertSame(TravelRequest::ACCEPTED, $made->status);
        $this->assertSame(1850000, $made->total_minor);
        $this->get(TravelController::statusUrl($made))->assertSee('To pay now: MVR 18,500')->assertSee('7730000123456');

        // Choosing twice is refused.
        $this->post(route('travel.requests.accept', ['locale' => 'en', 'key' => $made->status_key]), ['option' => $cheaper->id])
            ->assertSessionHasErrors('option');

        // Money recorded, verified by Finance, and only then paid.
        Livewire::actingAs($desk)->test(ManageTravelRequests::class)
            ->callTableAction('recordPayment', $made, data: ['method' => Payment::BANK_TRANSFER, 'amount' => 18500, 'paid_at' => '2026-11-25']);
        $this->assertSame(TravelRequest::ACCEPTED, $made->fresh()->status);
        app(Ledger::class)->reconcile(Payment::query()->sole(), null, $this->staff(Access::FINANCE));
        $this->assertSame(TravelRequest::PAID, $made->fresh()->status);

        $this->assertSame(['requested', 'quoted', 'accepted', 'paid'], $made->events()->pluck('status')->all());
    }

    public function test_an_expired_quote_cannot_be_chosen_and_a_stranger_cannot_choose_another_requests_option(): void
    {
        $requests = app(TravelRequests::class);
        $mine = $requests->submit(['wants_flight' => true, 'wants_hotel' => false, 'wants_visa' => false, 'destination' => 'Colombo', 'depart_on' => '2026-12-05', 'adults' => 1, 'contact_name' => 'A', 'contact_phone' => '1']);
        $theirs = $requests->submit(['wants_flight' => true, 'wants_hotel' => false, 'wants_visa' => false, 'destination' => 'Delhi', 'depart_on' => '2026-12-05', 'adults' => 1, 'contact_name' => 'B', 'contact_phone' => '2']);
        $requests->addOption($mine, 'SriLankan', null, Money::ofMajor(4000, 'MVR'));
        $other = $requests->addOption($theirs, 'IndiGo', null, Money::ofMajor(5000, 'MVR'));
        $requests->sendQuote($mine, 1, null);

        try {
            $requests->accept($mine, $other->id);
            $this->fail('An option from another request was accepted.');
        } catch (DeskRefusal $refusal) {
            $this->assertSame('Choose one of the options on this page.', $refusal->getMessage());
        }

        $this->travel(2)->days();
        $this->get(TravelController::statusUrl($mine))->assertSee('These prices have expired');
        $this->expectExceptionMessage('These prices have expired.');
        $requests->accept($mine->fresh(), $mine->options()->firstOrFail()->id);
    }

    public function test_the_form_needs_something_to_plan_and_the_page_needs_its_key(): void
    {
        $this->from('/en/travel')->post('/en/travel', $this->form(['wants' => []]))->assertSessionHasErrors('wants');
        $this->from('/en/travel')->post('/en/travel', $this->form(['return_on' => '2026-12-01']))->assertSessionHasErrors('return_on');
        $this->post('/en/travel', $this->form(['website' => 'spam']))->assertRedirect();
        $this->assertSame(0, TravelRequest::count());

        $made = app(TravelRequests::class)->submit(['wants_flight' => false, 'wants_hotel' => false, 'wants_visa' => true, 'destination' => 'UK', 'depart_on' => '2026-12-05', 'adults' => 1, 'contact_name' => 'A', 'contact_phone' => '1']);
        $this->get('/en/travel/requests/'.$made->reference)->assertNotFound();
        $this->get('/en/travel/requests/'.str_repeat('z', 40))->assertNotFound();
    }

    public function test_switched_off_there_is_no_page_and_only_travel_staff_see_the_board(): void
    {
        Services::save(['travel' => Services::OFF]);
        $this->get('/en/travel')->assertNotFound();

        $this->actingAs($this->staff(Access::TOUR_LEADER))->get('/staff/travel-requests')->assertForbidden();
        $this->actingAs($this->staff())->get('/staff/travel-requests')->assertOk();
    }

    public function test_cancelling_needs_no_money_and_forgetting_reaches_the_request(): void
    {
        $customer = Customer::factory()->create();
        $requests = app(TravelRequests::class);
        $made = $requests->submit(['wants_flight' => true, 'wants_hotel' => false, 'wants_visa' => false, 'destination' => 'Jeddah', 'depart_on' => '2026-12-05', 'adults' => 1, 'contact_name' => 'Ali Rasheed', 'contact_phone' => '7700000', 'notes' => 'Wheelchair at the airport please.'], $customer);
        $requests->addOption($made, 'Saudia', 'Window seat for Ali.', Money::ofMajor(9000, 'MVR'));
        $requests->sendQuote($made, 3, null);

        Livewire::actingAs($this->staff())->test(ListNotices::class)->call('loadTable')->assertSee('Trip options ready');

        $made->forceFill(['paid_minor' => 100])->save();
        try {
            $requests->cancel($made, 'Changed plans', null);
            $this->fail('A request with money on it was cancelled.');
        } catch (DeskRefusal) {
            $made->forceFill(['paid_minor' => 0])->save();
        }

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $row = DB::table('travel_requests')->where('id', $made->id)->first();
        $this->assertNotSame('Ali Rasheed', $row->contact_name);
        $this->assertStringNotContainsString('Wheelchair', (string) $row->notes);
        $this->assertStringNotContainsString('Window seat', (string) DB::table('travel_quote_options')->where('travel_request_id', $made->id)->value('details'));
    }
}
