<?php

namespace Modules\Umrah\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\Books;
use App\Services\Operations\Excursions;
use App\Services\Payments\Gateways;
use App\Services\Payments\Ledger;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Umrah\Filament\Resources\ExcursionBookings\Pages\ListExcursionBookings;
use Modules\Umrah\Filament\Resources\Excursions\Pages\ListExcursions;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\DepartureExcursion;
use Modules\Umrah\Models\ExcursionBooking;
use Modules\Umrah\Models\Traveller;
use Tests\TestCase;

/**
 * Additional trips (companies plan, Phase 3e, from Hijaz): priced in riyal,
 * each family's places their own payable with their own payments and
 * invoice.
 */
class ExcursionsTest extends TestCase
{
    use RefreshDatabase;

    private function departure(): Departure
    {
        return Departure::factory()->withSeats(40)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
    }

    private function party(Departure $departure, int $people, string $name, string $status = Booking::CONFIRMED): Booking
    {
        $customer = Customer::factory()->create(['name' => $name]);
        $booking = Booking::factory()->create(['customer_id' => $customer->id, 'departure_id' => $departure->id, 'seats' => $people]);
        $booking->forceFill(['status' => $status])->save();

        for ($i = 1; $i <= $people; $i++) {
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => $name.' '.$i]);
            $booking->travellers()->create(['traveller_id' => $traveller->id, 'occupancy' => 'quad', 'is_lead' => $i === 1]);
        }

        return $booking->fresh(['travellers.traveller', 'customer']);
    }

    private function taif(Departure $departure, ?int $capacity = null): DepartureExcursion
    {
        return DepartureExcursion::create([
            'departure_id' => $departure->id, 'title' => 'Taif day trip', 'currency' => 'SAR',
            'price_minor' => 15_000, 'capacity' => $capacity,
        ]);
    }

    /** Recorded by the office, then confirmed by Finance — the same two steps as a booking's money. */
    private function pay(ExcursionBooking $booking, int $riyals, array $details = []): Payment
    {
        $payment = app(Gateways::class)->for(Payment::CASH)->start($booking, Money::ofMajor($riyals, 'SAR'), $details);

        return app(Ledger::class)->reconcile($payment, 'Counted');
    }

    public function test_a_familys_places_are_one_trip_booking_priced_in_riyal(): void
    {
        $departure = $this->departure();
        $trip = $this->taif($departure, capacity: 3);
        $ali = $this->party($departure, 2, 'Aminath Ali');
        $hassan = $this->party($departure, 2, 'Mariyam Hassan');
        $elsewhere = $this->party($this->departure(), 1, 'Ibrahim Elsewhere');

        $result = app(Excursions::class)->signUp($trip, [
            ...$ali->travellers->modelKeys(), ...$hassan->travellers->modelKeys(), ...$elsewhere->travellers->modelKeys(),
        ]);

        $this->assertSame(3, $result['added']);
        $this->assertContains('Ibrahim Elsewhere 1: not travelling on this departure', $result['skipped']);
        $this->assertContains('Mariyam Hassan 2: the trip is full', $result['skipped']);

        $xb = ExcursionBooking::query()->where('booking_id', $ali->id)->sole();
        $this->assertMatchesRegularExpression('/^RIH-X-\d{4}-\d{4}$/', (string) $xb->reference);
        $this->assertSame('SAR 300', $xb->total()->format());
        $this->assertSame($ali->company_id, $xb->company_id, 'The trip booking took its booking\'s company.');
        $this->assertSame(ExcursionBooking::OPEN, $xb->status);
        $this->assertSame(0, $trip->fresh()->placesLeft());

        // An included trip still says who is going, at no charge.
        $ziyarah = DepartureExcursion::create(['departure_id' => $departure->id, 'title' => 'Makkah Ziyarah', 'is_included' => true, 'price_minor' => 9_900]);
        app(Excursions::class)->signUp($ziyarah, $ali->travellers->modelKeys());
        $this->assertSame(0, ExcursionBooking::query()->where('departure_excursion_id', $ziyarah->id)->sole()->total_minor);
    }

    public function test_money_for_the_trip_is_its_own_and_counts_once_finance_confirms_it(): void
    {
        $departure = $this->departure();
        $ali = $this->party($departure, 2, 'Aminath Ali');
        app(Excursions::class)->signUp($this->taif($departure), $ali->travellers->modelKeys());
        $xb = ExcursionBooking::query()->sole();

        $recorded = app(Gateways::class)->for(Payment::CASH)->start($xb, Money::ofMajor(300, 'SAR'));
        $this->assertSame(0, $xb->fresh()->paid_minor, 'Recorded is not received.');

        app(Ledger::class)->reconcile($recorded, 'Counted');
        $this->assertSame(30_000, $xb->fresh()->paid_minor);
        $this->assertSame(ExcursionBooking::PAID, $xb->fresh()->status);
        $this->assertSame(0, $ali->fresh()->paid_minor, 'The booking in rufiyaa is untouched.');

        // The departure's cash book and the collections report both see it.
        $book = app(Books::class)->cashBook($departure);
        $this->assertSame(30_000, $book['totals']['SAR']['in'] ?? null);
        $this->assertStringContainsString((string) $xb->reference, $book['lines'][0]['words']);
        $this->assertSame((string) $xb->reference, app(Books::class)->collections(now()->subDay(), now()->addDay())['rows'][0]['booking']);
    }

    public function test_paid_places_are_refunded_before_anybody_is_taken_off_or_cancelled(): void
    {
        $departure = $this->departure();
        $trip = $this->taif($departure, capacity: 2);
        $ali = $this->party($departure, 2, 'Aminath Ali');
        app(Excursions::class)->signUp($trip, $ali->travellers->modelKeys());
        $xb = ExcursionBooking::query()->sole();
        $this->pay($xb, 150);

        try {
            app(Excursions::class)->remove($xb->participants()->first());
            $this->fail('A paid place was taken off.');
        } catch (DeskRefusal) {
        }

        $this->expectException(DeskRefusal::class);
        app(Excursions::class)->cancel($xb->fresh());
    }

    public function test_cancelled_places_stop_counting_against_the_trip(): void
    {
        $departure = $this->departure();
        $trip = $this->taif($departure, capacity: 2);
        $ali = $this->party($departure, 2, 'Aminath Ali');
        app(Excursions::class)->signUp($trip, $ali->travellers->modelKeys());
        $this->assertSame(0, $trip->fresh()->placesLeft());

        app(Excursions::class)->cancel(ExcursionBooking::query()->sole(), 'Changed their mind');

        $this->assertSame(2, $trip->fresh()->placesLeft());
        $this->assertSame(ExcursionBooking::CANCELLED, ExcursionBooking::query()->sole()->status);
    }

    /** The third payable is reached by data:forget, or a deletion request reports itself honoured over a riyal payment's payer name. */
    public function test_forgetting_a_customer_reaches_their_trip_payments(): void
    {
        $departure = $this->departure();
        $ali = $this->party($departure, 1, 'Aminath Ali', Booking::COMPLETED);
        app(Excursions::class)->signUp($this->taif($departure), $ali->travellers->modelKeys());
        $xb = ExcursionBooking::query()->sole();
        $xb->forceFill(['notes' => 'Aishath Real Person will pay at the hotel'])->save();
        $payment = $this->pay($xb, 150, ['payer_name' => 'Aishath Real Person']);

        $this->artisan('data:forget', ['customer' => $ali->customer_id])->assertSuccessful();

        $this->assertStringNotContainsString('Real Person', (string) $payment->fresh()->payer_name);
        $this->assertStringNotContainsString('Real Person', (string) $xb->fresh()->notes);
        $this->assertSame(15_000, $payment->fresh()->amount_minor, 'The amount is the financial record and stays.');
    }

    public function test_the_office_makes_a_trip_signs_a_family_up_and_records_their_payment(): void
    {
        $departure = $this->departure();
        $ali = $this->party($departure, 2, 'Aminath Ali');
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $trips = Livewire::actingAs($admin)->test(ListExcursions::class);
        $trips->callAction('create', data: [
            'departure_id' => $departure->id, 'title' => 'Taif day trip', 'currency' => 'SAR',
            'price_minor' => 150, 'sort_order' => 0,
        ])->assertHasNoActionErrors();

        $trip = DepartureExcursion::query()->sole();
        $this->assertSame(15_000, $trip->price_minor, 'Typed in riyal, stored in halalas.');

        $trips->callTableAction('signUp', $trip, data: ['ids' => [$ali->travellers->first()->id], 'with_family' => true])
            ->assertHasNoTableActionErrors()
            ->assertNotified();
        $xb = ExcursionBooking::query()->sole();
        $this->assertSame(2, $xb->participants()->count(), 'The family came along.');

        Livewire::actingAs($admin)->test(ListExcursionBookings::class)
            ->assertCanSeeTableRecords([$xb])
            ->callTableAction('recordPayment', $xb, data: ['method' => Payment::CASH, 'amount' => 300, 'paid_at' => now()->toDateString()])
            ->assertHasNoTableActionErrors();

        $payment = Payment::query()->sole();
        $this->assertSame((new ExcursionBooking)->getMorphClass(), $payment->payable_type);
        $this->assertSame('SAR 300', $payment->money()->format());
        $this->assertSame(Payment::PENDING, $payment->status);

        $this->actingAs($admin)
            ->get(route('staff.excursion-invoice', ['excursionBooking' => $xb->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
