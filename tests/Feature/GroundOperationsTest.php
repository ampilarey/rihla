<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\RoomAssignment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Operations\Buses;
use App\Services\Operations\Cards;
use App\Services\Operations\Lists;
use App\Support\Access;
use App\Support\AuditContext;
use App\Support\DepartureReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Buses, lists, cards and tags — Hijaz's ground operations, brought across
 * (batch A3). The services and the routes; the screens are in
 * GroundOperationsAdminTest.
 */
class GroundOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(40)->create([
            'date_start' => now()->addDays(30),
            'date_end' => now()->addDays(44),
        ]);
    }

    /** A booking of $people for one customer, in the status given. */
    private function party(Departure $departure, int $people, string $name = 'Aminath Ali', string $status = Booking::CONFIRMED): Booking
    {
        $customer = Customer::factory()->create(['name' => $name, 'phone' => '7771234']);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => $people,
        ]);
        $booking->forceFill(['status' => $status])->save();

        for ($i = 1; $i <= $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create([
                'full_name' => $name.' '.$i,
                'passport_number' => 'P'.str_pad((string) $traveller_id = random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                'gender' => 'female',
                'date_of_birth' => now()->subYears(30),
            ]);

            $booking->travellers()->create([
                'traveller_id' => $traveller->getKey(),
                'occupancy' => 'quad',
                'is_lead' => $i === 1,
            ]);
        }

        return $booking->fresh(['travellers.traveller', 'customer']);
    }

    private function bus(Departure $departure, string $name, ?int $capacity = null, ?string $leader = null): BusGroup
    {
        return BusGroup::create([
            'departure_id' => $departure->getKey(),
            'name' => $name,
            'capacity' => $capacity,
            'leader_name' => $leader,
        ]);
    }

    public function test_buses_take_members_and_report_overfill_splits_and_the_unassigned(): void
    {
        $departure = $this->departure();
        $ali = $this->party($departure, 3);
        $hassan = $this->party($departure, 2, 'Hassan Ibrahim');
        $elsewhere = $this->party($this->departure(), 1, 'Mariyam Waheed');

        $bus1 = $this->bus($departure, 'Bus 1', capacity: 2, leader: 'Ahmed');
        $bus2 = $this->bus($departure, 'Bus 2', capacity: 10);

        $buses = app(Buses::class);

        $result = $buses->assign($bus1, $ali->travellers->modelKeys());
        $this->assertSame(3, $result['assigned']);
        $this->assertSame([], $result['skipped']);
        $this->assertContains('Bus 1 now has 3 people for 2 seats.', $result['warnings']);

        // Somebody on another departure is named, not moved.
        $result = $buses->assign($bus2, [$elsewhere->travellers->first()->getKey()]);
        $this->assertSame(0, $result['assigned']);
        $this->assertSame(['Mariyam Waheed 1: on another departure'], $result['skipped']);
        $this->assertNull($elsewhere->travellers->first()->fresh()->bus_group_id);

        // Moving one of Ali's party splits the family across two coaches.
        $third = $ali->travellers->last();
        $result = $buses->assign($bus2, [$third->getKey()]);
        $this->assertSame(1, $result['assigned']);
        $this->assertContains("Aminath Ali's family is split across Bus 1 and Bus 2.", $result['warnings']);
        $this->assertSame($bus2->getKey(), $third->fresh()->bus_group_id);

        $types = array_column($buses->problemsWith($departure), 'type');
        $this->assertContains(Buses::SPLIT_FAMILY, $types);
        $this->assertContains(Buses::NO_LEADER, $types, 'Bus 2 has nobody at its door and the departure has no tour leader.');
        $this->assertContains(Buses::UNASSIGNED, $types, 'Hassan\'s party is on no bus.');
        $this->assertNotContains(Buses::OVER_CAPACITY, $types, 'Bus 1 is back to two of two.');

        $unassigned = collect($buses->problemsWith($departure))->firstWhere('type', Buses::UNASSIGNED);
        $this->assertSame('2 confirmed travellers are on no bus.', $unassigned['detail']);
        $this->assertStringContainsString('Hassan Ibrahim 1', (string) $unassigned['who']);

        // Over capacity again, and a leader fixes the leader problem.
        $buses->assign($bus1, [$third->getKey()]);
        $bus2->update(['leader_name' => 'Ibrahim']);
        $types = array_column($buses->problemsWith($departure), 'type');
        $this->assertContains(Buses::OVER_CAPACITY, $types);
        $this->assertNotContains(Buses::NO_LEADER, $types);
        $this->assertNotContains(Buses::SPLIT_FAMILY, $types);

        // Taking people off, and deleting a bus, leave nobody on a coach that is gone.
        $this->assertSame(1, $buses->unassign([$third->getKey()]));
        $this->assertNull($third->fresh()->bus_group_id);

        $bus1->delete();
        $this->assertSame(0, BookingTraveller::query()->where('bus_group_id', $bus1->getKey())->count());

        // No buses at all: nothing to report, not "everybody is on no bus".
        $bus2->delete();
        $this->assertSame([], $buses->problemsWith($departure));
    }

    public function test_boarding_is_a_tick_that_moving_buses_resets(): void
    {
        $departure = $this->departure();
        $booking = $this->party($departure, 1);
        $member = $booking->travellers->first();
        $bus1 = $this->bus($departure, 'Bus 1');
        $bus2 = $this->bus($departure, 'Bus 2');

        $buses = app(Buses::class);
        $buses->assign($bus1, [$member->getKey()]);

        $buses->toggleCheckIn($member->fresh());
        $this->assertTrue($member->fresh()->isCheckedIn());
        $this->assertSame(1, $bus1->checkedInCount());

        $buses->toggleCheckIn($member->fresh());
        $this->assertFalse($member->fresh()->isCheckedIn());

        $buses->toggleCheckIn($member->fresh());
        $buses->assign($bus2, [$member->getKey()]);
        $this->assertFalse($member->fresh()->isCheckedIn(), 'A tick at one coach door says nothing about another.');
    }

    public function test_lists_are_built_from_the_same_rows_as_pdf_or_csv(): void
    {
        $departure = $this->departure();
        $hotel = DepartureHotel::factory()->create(['departure_id' => $departure->getKey(), 'name' => 'Swissotel Al Maqam', 'city' => 'makkah']);
        $room = $hotel->rooms()->create(['label' => '1204', 'capacity' => 4, 'gender' => 'female']);

        $confirmed = $this->party($departure, 2);
        $held = $this->party($departure, 1, 'Hassan Ibrahim', Booking::HELD);
        $this->party($departure, 1, 'Nobody Travelling', Booking::CANCELLED);

        $lead = $confirmed->travellers->first();
        $lead->update(['ticket_number' => '176-1234567890', 'seat_number' => '14A']);
        RoomAssignment::create(['room_id' => $room->getKey(), 'traveller_id' => $lead->traveller_id, 'booking_id' => $confirmed->getKey()]);

        $bus = $this->bus($departure, 'Bus 1', capacity: 40, leader: 'Ahmed');
        app(Buses::class)->assign($bus, $confirmed->travellers->modelKeys());

        $lists = app(Lists::class);

        $office = $lists->manifest($departure, 'office');
        $this->assertCount(3, $office->rows, 'Two confirmed, one held; the cancelled booking is nobody travelling.');
        $this->assertSame('Aminath Ali 1 (head)', $office->rows[0]['name'], 'Each family\'s head is marked on the office list.');
        $this->assertSame('Hassan Ibrahim 1 (head) *', $office->rows[2]['name'], 'Held is listed last and marked.');
        $this->assertSame('176-1234567890', $office->rows[0]['ticket']);
        $this->assertSame('Bus 1', $office->rows[0]['bus']);
        $this->assertSame('1204', $office->rows[0]['room_'.$hotel->getKey()]);
        $this->assertSame('', $office->rows[1]['room_'.$hotel->getKey()]);
        $this->assertSame('none', $office->rows[0]['visa']);
        $this->assertArrayHasKey('paid', $office->columns);
        $this->assertTrue($office->landscape);

        $this->assertArrayNotHasKey('paid', $lists->manifest($departure, 'office', withMoney: false)->columns, 'Money is a separate disclosure from a name.');

        $airline = $lists->manifest($departure, 'airline');
        $this->assertSame(['n', 'name', 'passport', 'nationality', 'dob', 'gender', 'expiry', 'ticket', 'seat'], array_keys($airline->columns));
        $this->assertSame('14A', $airline->rows[0]['seat']);
        $this->assertSame('Aminath Ali 1', $airline->rows[0]['name'], 'The airline is handed the name as on the passport.');

        $csv = $office->csv();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'A byte-order mark, so Excel reads the names as letters.');
        $this->assertStringContainsString('Booked by', $csv);
        $this->assertStringContainsString('Aminath Ali 1', $csv);
        $this->assertStringContainsString('7771234', $csv);

        $busList = $lists->busList($bus);
        $this->assertCount(2, $busList->rows);
        $this->assertSame('☐', $busList->rows[0]['tick']);
        app(Buses::class)->toggleCheckIn($lead->fresh());
        $this->assertSame('☑', $lists->busList($bus)->rows[0]['tick']);
        $this->assertStringContainsString('leader Ahmed', $busList->subtitle);

        $office = $lists->roomList($hotel);
        $hotelCopy = $lists->roomList($hotel, forHotel: true);
        $this->assertSame('1204', $office->rows[0]['room']);
        $this->assertSame('Aminath Ali 1 (head)', $office->rows[0]['name']);
        $this->assertSame('Aminath Ali 1', $hotelCopy->rows[0]['name'], 'The hotel copy carries no family markers.');
        $this->assertArrayHasKey('phone', $office->columns);
        $this->assertArrayNotHasKey('phone', $hotelCopy->columns, 'The hotel is handed names and passports, not phone numbers.');
        $this->assertArrayNotHasKey('booked_by', $hotelCopy->columns);
        $this->assertStringNotContainsString('7771234', $hotelCopy->csv());

        $payments = $lists->payments($departure);
        $this->assertCount(2, $payments->rows, 'Confirmed and held; not cancelled.');
        $this->assertSame($confirmed->reference, $payments->rows[0]['booking']);

        $documents = $lists->documents($departure);
        $this->assertSame('missing', $documents->rows[0]['passport_copy']);
        $this->assertSame('issued', $documents->rows[0]['ticket']);

        $this->assertStringStartsWith('%PDF', $lists->pdf($office)->output());
    }

    public function test_the_list_routes_are_gated_by_permission_and_audited(): void
    {
        $departure = $this->departure();
        $this->party($departure, 2);
        $bus = $this->bus($departure, 'Bus 1');
        $hotel = DepartureHotel::factory()->create(['departure_id' => $departure->getKey()]);

        $leader = $this->staff(Access::TOUR_LEADER);
        $finance = $this->staff(Access::FINANCE);
        $content = $this->staff(Access::CONTENT_MANAGER);

        $this->actingAs($content)->get(route('lists.manifest', ['departure' => $departure]))->assertForbidden();

        $this->actingAs($leader)->get(route('lists.manifest', ['departure' => $departure]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($leader)->get(route('lists.bus', ['busGroup' => $bus]))->assertOk();
        $this->actingAs($leader)->get(route('lists.rooms', ['departureHotel' => $hotel, 'hotel' => 1]))->assertOk();
        $this->actingAs($leader)->get(route('lists.manifest', ['departure' => $departure, 'preset' => 'nonsense']))->assertNotFound();

        // The leader prints names and never learns who is behind.
        $this->actingAs($leader)->get(route('lists.payments', ['departure' => $departure]))->assertForbidden();
        $this->actingAs($leader)->get(route('lists.documents', ['departure' => $departure]))->assertForbidden();
        $csv = $this->actingAs($leader)->get(route('lists.manifest', ['departure' => $departure, 'format' => 'csv']));
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringNotContainsString('Paid', strtok($csv->streamedContent(), "\n"), 'No money column for a reader without payment.viewAny.');

        // Finance prints the money list, and its manifest carries the column.
        $this->actingAs($finance)->get(route('lists.payments', ['departure' => $departure, 'format' => 'csv']))->assertOk();
        $csv = $this->actingAs($finance)->get(route('lists.manifest', ['departure' => $departure, 'format' => 'csv']));
        $this->assertStringContainsString('Paid', strtok($csv->streamedContent(), "\n"));

        // Every print is in the audit trail, as staff.
        $prints = AuditLog::query()->where('event', AuditLog::DOWNLOADED)->get();
        $this->assertGreaterThanOrEqual(6, $prints->count());
        $this->assertSame([AuditContext::STAFF], $prints->pluck('channel')->unique()->values()->all());
        $manifest = $prints->first(fn (AuditLog $log): bool => ($log->new_values['list'] ?? null) === 'manifest');
        $this->assertNotNull($manifest);
        $this->assertSame('office', $manifest->new_values['preset']);
        $this->assertSame(2, $manifest->new_values['rows']);
        $this->assertSame($leader->getKey(), $manifest->user_id);

        // A selection prints a shorter list.
        $one = BookingTraveller::query()->first();
        $csv = $this->actingAs($finance)->get(route('lists.manifest', ['departure' => $departure, 'format' => 'csv', 'ids' => $one->getKey()]));
        $this->assertCount(2, array_filter(explode("\n", trim($csv->streamedContent()))), 'A heading and one row.');
    }

    public function test_cards_are_issued_once_resolve_by_token_or_serial_and_print_with_a_qr(): void
    {
        $departure = $this->departure();
        $booking = $this->party($departure, 2);
        $member = $booking->travellers->first();
        $bus = $this->bus($departure, 'Bus 1');
        app(Buses::class)->assign($bus, [$member->getKey()]);

        $cards = app(Cards::class);

        $card = $cards->issue($member, Card::CUSTOMER);
        $this->assertSame($card->getKey(), $cards->issue($member, Card::CUSTOMER)->getKey(), 'Issued once.');
        $this->assertSame(Card::serialFor(Card::CUSTOMER, (int) $card->getKey()), $card->serial);
        $this->assertMatchesRegularExpression('/^RIH-C-\d{6}$/', $card->serial);
        $this->assertSame(40, strlen($card->qr_token));
        $this->assertStringEndsWith('/staff/scan?t='.$card->qr_token, $card->url());

        $tag = $cards->issue($member, Card::LUGGAGE);
        $this->assertStringStartsWith('RIH-L-', $tag->serial);
        $this->assertNotSame($card->getKey(), $tag->getKey(), 'A card and a tag are two things.');

        $this->assertSame($card->getKey(), $cards->resolve($card->qr_token)?->getKey());
        $this->assertSame($card->getKey(), $cards->resolve(strtolower($card->serial))?->getKey());
        $this->assertSame($card->getKey(), $cards->resolve($card->url())?->getKey(), 'The camera app hands over the whole link.');
        $this->assertNull($cards->resolve(''));
        $this->assertNull($cards->resolve('RIH-C-999999'));

        $fresh = $cards->reissue($member, Card::CUSTOMER);
        $this->assertNotSame($card->getKey(), $fresh->getKey());
        $this->assertNotNull($card->fresh()->revoked_at);
        $this->assertNull($cards->resolve($card->qr_token), 'A lost card\'s token stops working.');
        $this->assertSame($fresh->getKey(), $cards->resolve($fresh->serial)?->getKey());

        $qr = $cards->qrDataUri('https://example.invalid/x');
        $this->assertStringStartsWith('data:image/png;base64,', $qr);
        $image = imagecreatefromstring((string) base64_decode(substr($qr, strlen('data:image/png;base64,')), true));
        $this->assertNotFalse($image, 'The QR is a PNG dompdf can draw.');

        $pdf = $cards->sheet(collect([$member->fresh()]), Card::LUGGAGE);
        $this->assertStringStartsWith('%PDF', $pdf->output());
        $this->assertSame(1, $tag->fresh()->printed_count);
        $this->assertSame(0, $fresh->fresh()->printed_count, 'Only the kind printed is counted.');

        // The routes: a sheet for the people named, a bus, or a departure.
        $ops = $this->staff(Access::OPERATIONS_MANAGER);
        $this->actingAs($ops)->get(route('lists.cards', ['kind' => 'customer', 'ids' => $member->getKey()]))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($ops)->get(route('lists.cards', ['kind' => 'luggage', 'bus' => $bus->getKey()]))->assertOk();
        $this->actingAs($ops)->get(route('lists.cards', ['kind' => 'customer', 'departure' => $departure->getKey()]))->assertOk();
        $this->actingAs($ops)->get(route('lists.cards', ['kind' => 'customer']))->assertNotFound();
        $this->actingAs($ops)->get(route('lists.cards', ['kind' => 'luggage', 'bus' => 999]))->assertNotFound();
        $this->assertSame(2, $fresh->fresh()->printed_count);

        // The leader scans; the office prints.
        $this->actingAs($this->staff(Access::TOUR_LEADER))->get(route('lists.cards', ['kind' => 'customer', 'departure' => $departure->getKey()]))->assertForbidden();

        $print = AuditLog::query()->where('event', AuditLog::DOWNLOADED)->latest('id')->first();
        $this->assertSame('customer_cards', $print->new_values['document']);
        $this->assertSame(2, $print->new_values['count']);
    }

    public function test_the_departure_board_names_bus_problems_once_there_is_a_bus(): void
    {
        $departure = $this->departure();
        $this->party($departure, 2);

        $busConcerns = fn (): array => array_values(array_filter(
            DepartureReadiness::concerns($departure),
            fn (array $concern): bool => $concern['area'] === DepartureReadiness::TRANSPORT && str_contains($concern['headline'], 'bus'),
        ));

        $this->assertSame([], $busConcerns(), 'Silent until the office has made a bus.');

        $bus = $this->bus($departure, 'Bus 1', capacity: 1, leader: 'Ahmed');
        $concerns = $busConcerns();
        $this->assertCount(1, $concerns);
        $this->assertSame(DepartureReadiness::ATTENTION, $concerns[0]['severity']);
        $this->assertStringContainsString('2 confirmed travellers are on no bus.', $concerns[0]['detail']);

        app(Buses::class)->assign($bus, BookingTraveller::query()->pluck('id')->all());
        $concerns = $busConcerns();
        $this->assertStringContainsString('Bus 1: 2 people for 1 seats.', $concerns[0]['detail']);
    }
}
