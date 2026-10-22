<?php

namespace Tests\Feature;

use App\Exceptions\IllegalDepartureTransition;
use App\Filament\Pages\Books as BooksPage;
use App\Filament\Resources\Costs\Pages\EditDepartureCost;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Finance\Books;
use App\Services\Payments\Drivers\CashAtTheOffice;
use App\Services\Payments\Ledger;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The company books — batch C from Hijaz: a cash book per departure and
 * for the company, the months side by side, what is still owed and what
 * came in, each as a screen and a CSV, and a settled departure's books
 * closed.
 */
class BooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['finance.rates' => ['to' => 'MVR', 'as_of' => null]]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(int $daysAhead = 40): Departure
    {
        return Departure::factory()->withSeats(20)->create(['date_start' => now()->addDays($daysAhead), 'date_end' => now()->addDays($daysAhead + 14)]);
    }

    private function booking(Departure $departure, int $totalMinor, string $name, int $seats = 1): Booking
    {
        $customer = Customer::factory()->create(['name' => $name]);
        $booking = Booking::factory()->create(['customer_id' => $customer->getKey(), 'departure_id' => $departure->getKey(), 'seats' => $seats, 'currency' => 'MVR', 'total_minor' => $totalMinor]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create(['traveller_id' => Traveller::factory()->for($customer)->create()->getKey(), 'occupancy' => 'quad', 'is_lead' => true]);

        return $booking;
    }

    /** Cash recorded and verified, dated. */
    private function received(Booking $booking, int $major, string $on, User $finance): Payment
    {
        $payment = app(CashAtTheOffice::class)->start($booking, Money::ofMajor($major, 'MVR'), ['paid_at' => $on]);

        return app(Ledger::class)->reconcile($payment, null, $finance);
    }

    public function test_the_cash_book_is_what_moved_on_a_departure_and_the_company_has_one_of_its_own(): void
    {
        $finance = $this->staff(Access::FINANCE);
        $this->actingAs($finance);
        $departure = $this->departure();
        $aminath = $this->booking($departure, 3_600_000, 'Aminath Ali');
        $hassan = $this->booking($departure, 3_600_000, 'Hassan Ibrahim', seats: 2);

        $this->received($aminath, 10_000, '2026-09-05', $finance);
        $this->received($hassan, 20_000, '2026-09-20', $finance);
        $refund = app(Ledger::class)->refund(Payment::query()->orderBy('id')->first(), Money::ofMajor(2_000, 'MVR'), 'Changed room', $finance);
        $refund->forceFill(['paid_at' => '2026-09-25'])->save();

        // Paid, agreed and estimated costs; only the paid one is money that moved.
        DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::HOTEL, 'supplier' => 'Swissôtel', 'currency' => 'MVR', 'amount_minor' => 500_000, 'is_per_person' => true, 'status' => DepartureCost::PAID, 'incurred_on' => '2026-09-10']);
        DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::FLIGHT, 'supplier' => 'Saudia', 'currency' => 'USD', 'amount_minor' => 120_000, 'is_per_person' => false, 'status' => DepartureCost::COMMITTED, 'incurred_on' => '2026-09-12']);
        DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::FOOD, 'currency' => 'MVR', 'amount_minor' => 50_000, 'status' => DepartureCost::ESTIMATED]);
        // The company's own, in no journey's book.
        DepartureCost::create(['departure_id' => null, 'category' => DepartureCost::OFFICE, 'description' => 'October rent', 'currency' => 'MVR', 'amount_minor' => 1_200_000, 'status' => DepartureCost::PAID, 'incurred_on' => '2026-10-01']);

        $book = app(Books::class)->cashBook($departure);

        $this->assertSame(['2026-09-05', '2026-09-10', '2026-09-20', '2026-09-25'], array_map(fn (array $line): string => $line['on']->format('Y-m-d'), $book['lines']));
        $this->assertSame(['in', 'out', 'in', 'refund'], array_column($book['lines'], 'kind'));
        $this->assertSame(1_500_000, $book['lines'][1]['out'], 'MVR 5,000 a head for three confirmed travellers.');
        $this->assertSame('MVR 5,000 × 3', $book['lines'][1]['detail']);
        $this->assertStringContainsString('Hassan Ibrahim', $book['lines'][2]['words']);
        $this->assertSame(['MVR' => ['in' => 3_000_000, 'out' => 1_700_000]], $book['totals']);
        $this->assertSame('MVR 13,000', $book['net']?->format());

        // One month of it.
        $september = app(Books::class)->cashBook($departure, now()->parse('2026-09-15'));
        $this->assertCount(4, $september['lines']);
        $this->assertSame([], app(Books::class)->cashBook($departure, now()->parse('2026-08-15'))['lines']);

        // The company: its own paid costs and no payments, which belong to bookings.
        $company = app(Books::class)->cashBook(null);
        $this->assertCount(1, $company['lines']);
        $this->assertSame('Office and running costs · October rent', $company['lines'][0]['words']);
        $this->assertSame('The company', $company['lines'][0]['detail']);
        $this->assertSame(['MVR' => ['in' => 0, 'out' => 1_200_000]], $company['totals']);

        // The page shows the same, and its CSV is one download, audited.
        Livewire::actingAs($finance)->test(BooksPage::class)
            ->set('departureId', $departure->getKey())
            ->assertSee('Cash book')
            ->assertSee('MVR 5,000 × 3')
            ->assertSee('Hassan Ibrahim')
            ->assertSee('net MVR 13,000')
            ->set('departureId', null)
            ->assertSee('October rent');

        $csv = $this->actingAs($finance)->get(route('books.csv', ['report' => 'cash', 'departure' => $departure->getKey()]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->streamedContent();
        $this->assertStringContainsString('Date,What,Detail,Currency,In,Out', $csv);
        $this->assertStringContainsString('"MVR 5,000 × 3"', $csv);
        $this->assertStringContainsString('Total MVR', $csv);
        $this->assertTrue(AuditLog::query()->where('event', AuditLog::DOWNLOADED)->where('user_id', $finance->getKey())->exists());
    }

    public function test_months_still_owed_and_what_came_in_each_say_their_figures(): void
    {
        $finance = $this->staff(Access::FINANCE);
        $this->actingAs($finance);
        $this->travelTo(now()->parse('2026-10-21 10:00'));
        $departure = $this->departure();
        $aminath = $this->booking($departure, 3_600_000, 'Aminath Ali');
        $hassan = $this->booking($departure, 2_000_000, 'Hassan Ibrahim');

        $this->received($aminath, 10_000, '2026-09-05', $finance);
        $this->received($hassan, 20_000, '2026-10-02', $finance);
        $pending = app(CashAtTheOffice::class)->start($aminath, Money::ofMajor(5_000, 'MVR'));
        $this->assertSame(Payment::PENDING, $pending->status, 'Recorded is not received, and never in the books.');

        DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::VISA, 'currency' => 'SAR', 'amount_minor' => 30_000, 'status' => DepartureCost::PAID, 'incurred_on' => '2026-10-05']);
        DepartureCost::create(['departure_id' => null, 'category' => DepartureCost::OFFICE, 'currency' => 'MVR', 'amount_minor' => 100_000, 'status' => DepartureCost::PAID, 'incurred_on' => '2026-09-28']);

        $months = app(Books::class)->monthly(3);
        $this->assertSame(['2026-10', '2026-09', '2026-08'], array_column($months, 'month'));
        $this->assertSame(['MVR' => 2_000_000], $months[0]['in']);
        $this->assertSame(['SAR' => 30_000], $months[0]['out']);
        $this->assertNull($months[0]['net'], 'Riyals and no rate: no single figure.');
        $this->assertSame(['SAR'], $months[0]['unconvertible']);
        $this->assertSame(['MVR' => 1_000_000], $months[1]['in']);
        $this->assertSame(['MVR' => 100_000], $months[1]['out']);
        $this->assertSame('MVR 9,000', $months[1]['net']?->format());
        $this->assertSame([], $months[2]['in']);

        ExchangeRate::create(['currency' => 'SAR', 'to_currency' => 'MVR', 'rate' => 4.11, 'effective_on' => '2026-10-01']);
        $this->assertSame('MVR 18,767', app(Books::class)->monthly(1)[0]['net']?->format(), '20,000 in, 300 riyals out at 4.11.');

        // Hassan paid in full; Aminath's recorded-but-unverified 5,000 counts for nothing.
        $owed = app(Books::class)->outstanding();
        $this->assertSame(['Aminath Ali'], array_column($owed['rows'], 'customer'));
        $this->assertSame('MVR 26,000', $owed['rows'][0]['balance']->format());
        $this->assertSame(['MVR' => 2_600_000], $owed['totals']);

        $came = app(Books::class)->collections(now()->parse('2026-10-01'), now()->parse('2026-10-31'));
        $this->assertCount(1, $came['rows']);
        $this->assertSame('Hassan Ibrahim', $came['rows'][0]['customer']);
        $this->assertSame($finance->name, $came['rows'][0]['verified_by']);
        $this->assertSame(['MVR' => 2_000_000], $came['totals']);
        $this->assertSame(['cash' => ['MVR' => 2_000_000]], $came['byMethod']);

        $page = Livewire::actingAs($finance)->test(BooksPage::class);
        $page->set('tab', Books::MONTHS)->assertSee('Sep 2026')->assertSee('MVR 9,000');
        $page->set('tab', Books::OWED)->assertSee('Aminath Ali')->assertSee('MVR 26,000')->assertDontSee('Hassan Ibrahim');
        $page->set('tab', Books::COLLECTIONS)->set('from', '2026-10-01')->set('to', '2026-10-31')->assertSee('Hassan Ibrahim')->assertDontSee('Aminath Ali');

        foreach (['months' => 'Sep 2026', 'owed' => 'Aminath Ali', 'collections' => 'Hassan Ibrahim'] as $report => $expected) {
            $this->assertStringContainsString($expected, $this->actingAs($finance)->get(route('books.csv', ['report' => $report, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent());
        }
    }

    public function test_the_books_are_finances_operations_and_reportings_to_read_and_nobody_elses(): void
    {
        foreach ([Access::FINANCE, Access::OPERATIONS_MANAGER, Access::REPORTING] as $role) {
            $this->actingAs($this->staff($role))->get(BooksPage::getUrl())->assertOk();
            $this->actingAs($this->staff($role))->get(route('books.csv', ['report' => 'owed']))->assertOk();
        }

        foreach ([Access::BOOKING_STAFF, Access::CONTENT_MANAGER, Access::TOUR_LEADER] as $role) {
            $this->actingAs($this->staff($role))->get(BooksPage::getUrl())->assertForbidden();
            $this->actingAs($this->staff($role))->get(route('books.csv', ['report' => 'owed']))->assertForbidden();
        }

        $this->actingAs($this->staff(Access::FINANCE))->get('/staff-books/everything')->assertNotFound();
    }

    public function test_settling_closes_the_books_and_waits_for_every_estimate(): void
    {
        $finance = $this->staff(Access::FINANCE);
        $ops = $this->staff(Access::OPERATIONS_MANAGER);
        $departure = Departure::factory()->withSeats(20)->create(['date_start' => now()->subDays(40), 'date_end' => now()->subDays(26), 'status' => Departure::RETURNED]);
        $estimate = DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::FOOD, 'currency' => 'MVR', 'amount_minor' => 50_000, 'status' => DepartureCost::ESTIMATED]);
        $paid = DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::HOTEL, 'currency' => 'MVR', 'amount_minor' => 900_000, 'status' => DepartureCost::PAID]);

        try {
            $departure->transitionTo(Departure::SETTLED);
            $this->fail('Settled over an estimate.');
        } catch (IllegalDepartureTransition $e) {
            $this->assertStringContainsString('1 cost is still an estimate', $e->getMessage());
        }

        $estimate->forceFill(['status' => DepartureCost::COMMITTED])->save();
        $this->actingAs($finance);
        $departure->transitionTo(Departure::SETTLED, 'Books closed');

        // Closed: Finance, who may edit any other cost, may not touch these.
        $this->assertTrue($finance->can('update', DepartureCost::create(['departure_id' => $this->departure()->getKey(), 'category' => DepartureCost::FOOD, 'currency' => 'MVR', 'amount_minor' => 1_000])));
        $this->assertFalse($finance->can('update', $paid->fresh()));
        $this->assertFalse($ops->can('update', $paid->fresh()));
        $this->assertFalse($finance->can('delete', $paid->fresh()));

        Livewire::actingAs($finance)->test(EditDepartureCost::class, ['record' => $paid->getKey()])->assertForbidden();

        Livewire::actingAs($finance)->test(BooksPage::class)
            ->set('departureId', $departure->getKey())
            ->assertSee('Settled — books closed');
    }
}
