<?php

namespace Modules\Transport\Tests;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Island;
use App\Models\Notice;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\Services;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Transport\Filament\Pages\BoatScan;
use Modules\Transport\Filament\Resources\Sailings\Pages\ManageSailings;
use Modules\Transport\Http\Controllers\BoatController;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Services\Manifest;
use Modules\Transport\Services\TransportDesk;
use Tests\TestCase;

/**
 * Transport T3 (docs/TRANSPORT_PLAN.md): the crew checks a boat on board
 * from a phone, the manifest shows who boarded, and cancelling a sailing
 * puts every booker on the notice queue.
 */
class TransportBoardingTest extends TestCase
{
    use RefreshDatabase;

    private Sailing $sailing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 06:00'));

        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);
        [$male, $fulidhoo] = Island::query()->orderBy('id')->limit(2)->get()->all();
        $boat = Vessel::create(['partner_id' => $rihla->id, 'name' => 'Rihla One', 'kind' => Vessel::SPEEDBOAT, 'seats' => 10, 'registration_number' => 'SB-1234']);
        $route = BoatRoute::create([
            'partner_id' => $rihla->id, 'origin_island_id' => $male->id, 'destination_island_id' => $fulidhoo->id,
            'duration_minutes' => 90, 'currency' => 'MVR', 'adult_fare_minor' => 50000,
        ]);
        $this->sailing = Sailing::create(['boat_route_id' => $route->id, 'vessel_id' => $boat->id, 'departs_at' => now()->addHours(2), 'seats_total' => 10]);
    }

    private function desk(): TransportDesk
    {
        return app(TransportDesk::class);
    }

    private function crew(string $role = Access::OPERATIONS_MANAGER): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_the_qr_is_a_link_to_the_boarding_page_and_the_crew_boards_a_confirmed_passenger(): void
    {
        Services::save(['transport' => Services::ON]);
        $booking = $this->desk()->book($this->sailing, [['full_name' => 'Aminath Ali'], ['full_name' => 'Ali Hassan']], 'Aminath', '7771234');
        $this->desk()->confirm($booking);
        $ticket = $booking->tickets()->orderBy('id')->firstOrFail();

        // The ticket page's QR decodes to the staff scan page with the token.
        $this->assertSame(BoatScan::getUrl(['t' => $ticket->token], panel: 'staff'), url('/staff/boat-scan').'?t='.$ticket->token);
        $this->get(BoatController::ticketUrl($booking))->assertOk();

        $crew = $this->crew();
        $this->actingAs($crew)->get('/staff/boat-scan?t='.$ticket->token)->assertOk()->assertSee('Aminath Ali');

        Livewire::actingAs($crew)->withQueryParams(['t' => $ticket->token])->test(BoatScan::class)
            ->assertSee('Board Aminath Ali')
            ->call('board')
            ->assertNotified('Aminath Ali is on board')
            ->assertSee('1 of 2');

        $this->assertNotNull($ticket->fresh()->boarded_at);
        $this->assertSame($crew->id, $ticket->fresh()->boarded_by);
        $this->assertSame(Sailing::BOARDING, $this->sailing->fresh()->status, 'The first passenger on closes the sale.');
        $this->assertFalse($this->sailing->fresh()->isOnSale());

        $this->expectExceptionMessage('Aminath Ali boarded at 06:00.');
        $this->desk()->board($ticket->fresh());
    }

    public function test_an_unconfirmed_or_cancelled_ticket_does_not_board(): void
    {
        $held = $this->desk()->book($this->sailing, [['full_name' => 'Unpaid']], 'X', '1');

        try {
            $this->desk()->board($held->tickets()->firstOrFail());
            $this->fail('An unpaid hold boarded.');
        } catch (DeskRefusal $refusal) {
            $this->assertStringContainsString('not paid or confirmed yet', $refusal->getMessage());
        }

        $this->desk()->cancel($held);
        $this->expectExceptionMessage('is cancelled: this ticket is not valid.');
        $this->desk()->board($held->tickets()->firstOrFail());
    }

    public function test_the_scan_page_finds_a_ticket_however_it_was_read_and_refuses_strangers(): void
    {
        $booking = $this->desk()->book($this->sailing, [['full_name' => 'Hawwa']], 'Hawwa', '2');
        $token = $booking->tickets()->firstOrFail()->token;

        $this->assertNotNull($this->desk()->resolve('https://rihla.mv/staff/boat-scan?t='.$token));
        $this->assertNotNull($this->desk()->resolve('rihla-boat:'.$token));
        $this->assertNotNull($this->desk()->resolve(' '.$token.' '));
        $this->assertNull($this->desk()->resolve($booking->reference));
        $this->assertNull($this->desk()->resolve(str_repeat('x', 40)));

        $this->actingAs($this->crew(Access::TOUR_LEADER))->get('/staff/boat-scan')->assertForbidden();
    }

    public function test_the_manifest_lists_every_seat_and_who_boarded_and_its_download_is_recorded(): void
    {
        $one = $this->desk()->book($this->sailing, [['full_name' => 'Aminath Ali'], ['full_name' => 'Ali Hassan', 'is_child' => true]], 'Aminath', '7771234');
        $this->desk()->confirm($one);
        $this->desk()->book($this->sailing, [['full_name' => 'Mariyam Unpaid']], 'Mariyam', '7779999');
        $gone = $this->desk()->book($this->sailing, [['full_name' => 'Cancelled Person']], 'C', '3');
        $this->desk()->cancel($gone);
        $this->desk()->board($one->tickets()->orderBy('id')->firstOrFail());

        $list = app(Manifest::class)->list($this->sailing);
        $this->assertSame(['Aminath Ali', 'Ali Hassan', 'Mariyam Unpaid'], array_column($list->rows, 'name'));
        $this->assertSame('06:00', $list->rows[0]['boarded']);
        $this->assertSame('', $list->rows[1]['boarded']);
        $this->assertStringEndsWith(' *', (string) $list->rows[2]['booking']);
        $this->assertStringContainsString('1 of 3 on board', $list->subtitle);
        $this->assertStringContainsString('SB-1234', $list->subtitle);

        $crew = $this->crew(Access::BOOKING_STAFF);
        Livewire::actingAs($crew)->test(ManageSailings::class)
            ->callTableAction('manifest', $this->sailing)
            ->assertFileDownloaded();

        $this->assertTrue(AuditLog::query()->where('event', AuditLog::DOWNLOADED)->where('user_id', $crew->id)
            ->where('auditable_id', $this->sailing->id)->exists());
    }

    public function test_cancelling_a_sailing_puts_every_booker_on_the_notice_queue_and_their_page_says_so(): void
    {
        Services::save(['transport' => Services::ON]);
        $one = $this->desk()->book($this->sailing, [['full_name' => 'Aminath Ali']], 'Aminath Ali', '+960 777 1234');
        $this->desk()->confirm($one);
        $two = $this->desk()->book($this->sailing, [['full_name' => 'Hassan']], 'Hassan', '7775555');

        Livewire::actingAs($this->crew())->test(ManageSailings::class)
            ->callTableAction('cancel', $this->sailing, data: ['reason' => 'Rough sea, the coast guard has stopped small boats.'])
            ->assertNotified();

        $notices = Notice::query()->where('kind', Notice::SAILING_CANCELLED)->get();
        $this->assertCount(2, $notices);

        $notice = $notices->firstWhere('noticeable_id', $one->id);
        $this->assertNotNull($notice);
        $this->assertSame('Aminath Ali', $notice->recipientName());
        $this->assertSame($one->reference, $notice->subjectReference());
        $this->assertStringContainsString('Rough sea', (string) $notice->body);
        $this->assertStringStartsWith('https://wa.me/9607771234?text=', (string) $notice->whatsappUrl());

        $this->get(BoatController::ticketUrl($two))->assertOk()
            ->assertSee('This sailing is cancelled.')
            ->assertSee('Rough sea, the coast guard has stopped small boats.');

        // The queue lists them by the name the booker gave.
        Livewire::actingAs($this->crew())->test(ListNotices::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords($notices)
            ->assertSee('Aminath Ali')
            ->assertSee('Sailing cancelled');
    }

    public function test_forgetting_a_customer_reaches_the_notices_on_their_boat_bookings(): void
    {
        $customer = Customer::factory()->create();
        $booking = $this->desk()->book($this->sailing, [['full_name' => 'Aminath Ali']], 'Aminath Ali', '7771234', $customer);
        $this->desk()->cancelSailing($this->sailing, 'Engine trouble.');
        $this->assertSame(1, Notice::query()->where('noticeable_id', $booking->id)->count());

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $this->assertStringNotContainsString('Engine trouble', (string) DB::table('notices')->where('noticeable_id', $booking->id)->value('body'));
    }
}
