<?php

namespace Tests\Feature;

use App\Exceptions\NoSeatsAvailable;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Discount;
use App\Models\Package;
use App\Models\PaymentSchedule;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Booking\BookingDesk;
use App\Support\Access;
use App\Support\Audience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Taking a booking at the desk, and the money decisions on one afterwards.
 *
 * The desk does what the checkout does — seats under the row lock, the
 * product frozen, every traveller priced by room and age — in one
 * transaction, and adds what the checkout never had: extras, fees and
 * discounts as lines, and instalments.
 */
class BookingDeskTest extends TestCase
{
    use RefreshDatabase;

    private Departure $departure;

    private Customer $customer;

    private Traveller $adult;

    private Traveller $spouse;

    private Traveller $child;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::factory()->create(['title' => ['en' => 'Shawwal Umrah']]);

        $this->departure = Departure::factory()->withSeats(10)->create([
            'package_id' => $package->getKey(),
            'date_start' => now()->addDays(90)->toDateString(),
            'date_end' => now()->addDays(104)->toDateString(),
        ]);
        $this->departure->priceTiers()->create(['occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 3_200_000]);
        $this->departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2_850_000]);
        $this->departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'child', 'amount_minor' => 2_000_000]);
        $this->departure->priceTiers()->create(['audience' => 'tourist', 'occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 180_000]);

        $this->customer = Customer::factory()->create(['name' => 'Aminath Ali', 'phone' => '7771234']);
        $this->adult = Traveller::factory()->for($this->customer)->create(['full_name' => 'Aminath Ali', 'date_of_birth' => now()->subYears(40)]);
        $this->spouse = Traveller::factory()->for($this->customer)->create(['full_name' => 'Hassan Ali', 'date_of_birth' => now()->subYears(42)]);
        $this->child = Traveller::factory()->for($this->customer)->create(['full_name' => 'Yusuf Ali', 'date_of_birth' => now()->subYears(8)]);
    }

    private function desk(): BookingDesk
    {
        return app(BookingDesk::class);
    }

    private function open(?array $party = null): Booking
    {
        return $this->desk()->open($this->departure, $this->customer, $party ?? [
            ['traveller_id' => $this->adult->getKey(), 'occupancy' => 'quad'],
        ]);
    }

    public function test_a_party_is_priced_by_room_and_age_and_holds_its_seats(): void
    {
        $booking = $this->desk()->open($this->departure, $this->customer, [
            ['traveller_id' => $this->adult->getKey(), 'occupancy' => 'double'],
            ['traveller_id' => $this->spouse->getKey(), 'occupancy' => 'double'],
            ['traveller_id' => $this->child->getKey(), 'occupancy' => 'quad'],
        ], notes: 'Lower floor please');

        $this->assertMatchesRegularExpression('/^RIH-B-\d{4}-\d{4}$/', $booking->reference);
        $this->assertSame(Booking::HELD, $booking->status, 'Opened at the desk, the seats are held at once.');
        $this->assertSame(3, $booking->seats);
        $this->assertSame('MVR', $booking->currency);
        $this->assertSame(3_200_000 * 2 + 2_000_000, $booking->total_minor);
        $this->assertSame('Lower floor please', $booking->notes);
        $this->assertSame('Shawwal Umrah', $booking->package_snapshot['package']['title']['en'], 'The product is frozen, as the checkout freezes it.');

        $lines = $booking->travellers;
        $this->assertTrue($lines->firstWhere('traveller_id', $this->adult->getKey())->is_lead);
        $this->assertSame('child', $lines->firstWhere('traveller_id', $this->child->getKey())->pax_type);
        $this->assertCount(3, $booking->lines, 'One seat line per traveller, for the invoice.');

        $this->departure->refresh();
        $this->assertSame(3, $this->departure->capacity_held);
        $this->assertSame(0, $this->departure->capacity_confirmed);

        $hold = $booking->seatHolds()->sole();
        $this->assertTrue($hold->expires_at->gt(now()->addHours(23)), 'A day, not a checkout\'s fifteen minutes.');
        $this->assertSame('Opened at the desk.', $booking->transitions()->latest('id')->first()->reason);
    }

    public function test_a_child_with_no_child_price_pays_the_adult_price_as_on_the_website(): void
    {
        $booking = $this->open([['traveller_id' => $this->child->getKey(), 'occupancy' => 'double']]);

        $this->assertSame(3_200_000, $booking->travellers->sole()->amount_minor);
        $this->assertSame('adult', $booking->travellers->sole()->pax_type);
    }

    public function test_a_visitor_is_priced_from_the_visitor_list_in_its_currency(): void
    {
        $booking = $this->desk()->open($this->departure, $this->customer, [
            ['traveller_id' => $this->adult->getKey(), 'occupancy' => 'quad'],
        ], Audience::TOURIST);

        $this->assertSame('USD', $booking->currency);
        $this->assertSame(180_000, $booking->total_minor);
    }

    public function test_a_room_the_departure_does_not_price_is_refused_and_nothing_is_written(): void
    {
        try {
            $this->open([['traveller_id' => $this->adult->getKey(), 'occupancy' => 'single']]);
            $this->fail('A room with no price was sold.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('single room', $e->getMessage());
        }

        $this->assertSame(0, Booking::query()->count());
        $this->assertSame(0, $this->departure->fresh()->capacity_held, 'The hold rolled back with the booking.');
    }

    public function test_a_full_departure_refuses_the_party_whole(): void
    {
        $this->departure->forceFill(['capacity_total' => 2])->save();

        $this->expectException(NoSeatsAvailable::class);

        $this->desk()->open($this->departure, $this->customer, [
            ['traveller_id' => $this->adult->getKey(), 'occupancy' => 'quad'],
            ['traveller_id' => $this->spouse->getKey(), 'occupancy' => 'quad'],
            ['traveller_id' => $this->child->getKey(), 'occupancy' => 'quad'],
        ]);
    }

    public function test_somebody_elses_traveller_cannot_be_put_on_the_booking(): void
    {
        $stranger = Traveller::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->open([['traveller_id' => $stranger->getKey(), 'occupancy' => 'quad']]);
    }

    public function test_extras_and_fees_are_lines_and_can_be_removed(): void
    {
        $booking = $this->open();

        $fee = $this->desk()->addLine($booking, BookingLine::FEE, 'Visa fee', 1_200);
        $zamzam = $this->desk()->addLine($booking, BookingLine::EXTRA, 'Zamzam 5L', 150, 2);

        $this->assertSame(2_850_000 + 120_000 + 30_000, $booking->fresh()->total_minor);
        $this->assertSame(2, $zamzam->quantity);
        $this->assertSame(15_000, $zamzam->unit_amount_minor);

        $this->desk()->removeLine($fee);
        $this->assertSame(2_850_000 + 30_000, $booking->fresh()->total_minor);

        try {
            $this->desk()->removeLine($booking->lines()->where('type', BookingLine::SEAT)->firstOrFail());
            $this->fail('A seat line was removed as if it were a fee.');
        } catch (InvalidArgumentException) {
            $this->assertSame(2_850_000 + 30_000, $booking->fresh()->total_minor);
        }
    }

    public function test_a_discount_is_a_negative_line_with_a_reason_and_an_approver(): void
    {
        $finance = User::factory()->create()->assignRole(Access::FINANCE);
        $this->actingAs($finance);
        $booking = $this->open();

        $fixed = $this->desk()->discount($booking, Discount::FIXED, '500', 'Returning pilgrim');

        $this->assertSame(-50_000, $fixed->amount_minor);
        $this->assertSame($finance->getKey(), $fixed->approved_by);
        $this->assertSame(2_800_000, $booking->fresh()->total_minor);
        $this->assertSame('Discount: Returning pilgrim', $fixed->line->description);

        // A share of what is left, frozen as an amount.
        $percent = $this->desk()->discount($booking, Discount::PERCENT, '10', 'Group of ten');
        $this->assertSame(-280_000, $percent->amount_minor);
        $this->assertSame(2_520_000, $booking->fresh()->total_minor);

        $this->desk()->reverseDiscount($percent);
        $this->assertSame(2_800_000, $booking->fresh()->total_minor);
        $this->assertNotNull($percent->fresh()->reversed_at, 'The row stays and says so.');
        $this->assertNull($percent->fresh()->line, 'The line is gone.');

        $this->desk()->reverseDiscount($percent->fresh());
        $this->assertSame(2_800_000, $booking->fresh()->total_minor, 'Taking back twice takes back once.');
    }

    public function test_a_discount_cannot_exceed_the_total_or_lack_a_reason(): void
    {
        $booking = $this->open();

        try {
            $this->desk()->discount($booking, Discount::FIXED, '99999', 'Too much');
            $this->fail('A discount larger than the booking was given.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be more than', $e->getMessage());
        }

        try {
            $this->desk()->discount($booking, Discount::FIXED, '100', '   ');
            $this->fail('A discount with no reason was given.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $this->assertSame(2_850_000, $booking->fresh()->total_minor);
        $this->assertSame(0, Discount::query()->count());
    }

    public function test_nothing_can_be_charged_to_a_booking_that_is_over(): void
    {
        $booking = $this->open();
        $booking->transitionTo(Booking::CANCELLED, 'Changed their minds');

        $this->expectException(InvalidArgumentException::class);

        $this->desk()->addLine($booking->fresh(), BookingLine::FEE, 'Late fee', 100);
    }

    public function test_an_instalment_is_overdue_only_once_the_checked_money_falls_short(): void
    {
        $booking = $this->open();

        $deposit = $booking->schedule()->create(['label' => 'Deposit', 'amount_minor' => 1_000_000, 'due_on' => now()->subDays(10)->toDateString()]);
        $balance = $booking->schedule()->create(['label' => 'Balance', 'amount_minor' => 1_850_000, 'due_on' => now()->addDays(30)->toDateString()]);

        $this->assertTrue($deposit->isOverdue(), 'Nothing paid, deposit due ten days ago.');
        $this->assertFalse($balance->isOverdue(), 'Not due yet.');
        $this->assertSame('MVR 10,000', $deposit->outstanding()->format());

        // Half the deposit checked in: still behind, by the other half.
        $booking->forceFill(['paid_minor' => 500_000])->save();
        $this->assertTrue($deposit->fresh()->isOverdue());
        $this->assertSame('MVR 5,000', $deposit->fresh()->outstanding()->format());

        $booking->forceFill(['paid_minor' => 1_000_000])->save();
        $this->assertFalse($deposit->fresh()->isOverdue());
        $this->assertSame('MVR 0', $deposit->fresh()->outstanding()->format());

        // Earlier instalments count first: with the deposit covered and the
        // balance now due, the balance is the one behind.
        $this->travelTo(now()->addDays(31));
        $this->assertTrue($balance->fresh()->isOverdue());
        $this->assertSame('MVR 18,500', $balance->fresh()->outstanding()->format());

        $booking->transitionTo(Booking::CANCELLED, 'Visa refused');
        $this->assertFalse($balance->fresh()->isOverdue(), 'A cancelled booking owes nothing on a schedule.');
        $this->assertSame(2, PaymentSchedule::query()->count());
    }
}
