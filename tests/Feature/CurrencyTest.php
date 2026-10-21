<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\ExchangeRates\ExchangeRateResource;
use App\Filament\Resources\ExchangeRates\Pages\CreateExchangeRate;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\ExchangeRate;
use App\Models\Payment;
use App\Models\PriceTier;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Payments\Ledger;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use App\Support\JourneyProfit;
use App\Support\Money;
use App\Support\Rates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Three currencies — batch C from Hijaz, as the owner chose: prices and
 * balances in rufiyaa, a payment in dollars or riyals credited at the rate
 * Finance records, and equivalents on the site from a rate table.
 */
class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        // No rate from config unless a test says so: nothing is invented.
        config(['finance.rates' => ['to' => 'MVR', 'as_of' => null]]);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function booking(int $totalMinor = 3_600_000): Booking
    {
        $customer = Customer::factory()->create();
        $departure = Departure::factory()->withSeats(10)->create(['date_start' => now()->addDays(60), 'date_end' => now()->addDays(74)]);

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => $departure->getKey(),
            'seats' => 1,
            'currency' => 'MVR',
            'total_minor' => $totalMinor,
        ]);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();
        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create(['full_name' => 'Hawwa Latheefa'])->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        return $booking;
    }

    private function rate(string $currency, float $rate, ?string $from = null): ExchangeRate
    {
        return ExchangeRate::create(['currency' => $currency, 'to_currency' => 'MVR', 'rate' => $rate, 'effective_on' => $from ?? today()->toDateString()]);
    }

    public function test_money_knows_riyals(): void
    {
        $this->assertSame('SAR 650', Money::ofMajor(650, 'SAR')->format());
        $this->assertSame(65_000, Money::ofMajor(650, 'SAR')->minor);
        $this->assertSame(['MVR', 'USD', 'SAR'], Rates::currencies());
    }

    public function test_a_rate_comes_from_the_table_then_config_and_never_from_nowhere(): void
    {
        $price = Money::ofMajor(28_500);

        $this->assertNull(Rates::rate('USD'));
        $this->assertSame([], Rates::equivalents($price));
        $this->assertNull(Rates::equivalentsWords($price));
        $this->assertSame(1.0, Rates::rate('MVR'));

        try {
            Rates::toBase(Money::ofMajor(650, 'USD'));
            $this->fail('A rate was invented.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('No exchange rate is known for USD', $e->getMessage());
        }

        // config/finance.php, as it always was: minor units per unit.
        config(['finance.rates.USD' => 1542]);
        $this->assertSame(15.42, Rates::rate('USD'));
        $this->assertSame('≈ USD 1,848', Rates::equivalentsWords($price), 'To the whole unit, and only for currencies with a rate.');
        $this->assertNull(Rates::asOf('USD'));

        // The table wins over config, and only a line whose day has come.
        $this->rate('USD', 15.50);
        $this->rate('USD', 16.00, today()->addDay()->toDateString());
        $this->rate('SAR', 4.11);
        $this->assertSame(15.5, Rates::rate('USD'));
        $this->assertSame(4.11, Rates::rate('SAR'));
        $this->assertSame(today()->toDateString(), Rates::asOf('USD')?->toDateString());
        $this->assertSame('MVR 10,075', Rates::toBase(Money::ofMajor(650, 'USD'))->format());
        $this->assertSame('MVR 10,023', Rates::toBase(Money::ofMajor(650, 'USD'), 15.42)->format(), 'A rate given wins over the table.');
        $this->assertSame('≈ USD 1,839 · SAR 6,934', Rates::equivalentsWords($price));

        $this->travel(2)->days();
        $this->assertSame(16.0, Rates::rate('USD'), 'Tomorrow\'s line applies once tomorrow comes.');
    }

    public function test_a_payment_received_in_dollars_is_credited_in_rufiyaa_at_the_rate(): void
    {
        $finance = $this->staff(Access::FINANCE);
        $booking = $this->booking();
        $this->rate('USD', 15.42);

        $page = fn () => Livewire::actingAs($this->staff(Access::BOOKING_STAFF))->test(EditBooking::class, ['record' => $booking->getKey()]);

        $page()->callAction('recordPayment', [
            'method' => Payment::BANK_TRANSFER,
            'received_currency' => 'USD',
            'amount' => 650,
            'exchange_rate' => 15.42,
            'payer_name' => 'A relative in Dubai',
        ])->assertHasNoActionErrors()->assertNotified('Recorded MVR 10,023 — received USD 650 at 15.4200');

        $payment = Payment::sole();
        $this->assertSame('MVR', $payment->currency);
        $this->assertSame(1_002_300, $payment->amount_minor, '650 dollars at 15.42 is MVR 10,023.00, credited in the booking\'s currency.');
        $this->assertSame('USD', $payment->received_currency);
        $this->assertSame(65_000, $payment->received_minor);
        $this->assertSame(15.42, $payment->exchange_rate);
        $this->assertSame('USD 650 at 15.4200', $payment->receivedWords());

        // The ledger only ever sees the credit.
        app(Ledger::class)->reconcile($payment, null, $finance);
        $this->assertSame(1_002_300, $booking->fresh()->paid_minor);
        $this->assertSame('MVR 25,977', $booking->fresh()->balance()->format());

        Livewire::actingAs($finance)->test(ListPayments::class)->set('activeTab', 'received')->assertSee('USD 650 at 15.4200');
        $this->assertStringContainsString('Received USD 650 at 15.4200', view('pdf.receipt', [
            'payment' => $payment->fresh(),
            'booking' => $booking->fresh(),
            'issuer' => ['name' => 'Rihla', 'address' => '', 'phone' => '', 'email' => '', 'registration' => ''],
            'locale' => 'en',
        ])->render());

        // The rate typed wins over the table; a rate nobody knows refuses.
        $page()->callAction('recordPayment', ['method' => Payment::CASH, 'received_currency' => 'USD', 'amount' => 100, 'exchange_rate' => 15.00]);
        $this->assertSame(150_000, Payment::query()->latest('id')->first()?->amount_minor);

        // No rate known for riyals and none typed: the form will not have it.
        $page()->callAction('recordPayment', ['method' => Payment::CASH, 'received_currency' => 'SAR', 'amount' => 100, 'exchange_rate' => null])
            ->assertHasActionErrors(['exchange_rate' => 'required']);
        $this->assertSame(2, Payment::count());

        // In the booking's own currency nothing new is written.
        $page()->callAction('recordPayment', ['method' => Payment::CASH, 'received_currency' => 'MVR', 'amount' => 500]);
        $plain = Payment::query()->latest('id')->first();
        $this->assertSame(50_000, $plain?->amount_minor);
        $this->assertNull($plain?->received_currency);
        $this->assertNull($plain?->receivedWords());
    }

    public function test_the_rate_table_is_finances_and_prices_show_equivalents_only_with_a_rate(): void
    {
        $departure = Departure::factory()->withSeats(10)->create(['date_start' => now()->addDays(60), 'date_end' => now()->addDays(74)]);
        $departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => PriceTier::ADULT, 'amount_minor' => 2_850_000, 'currency' => 'MVR']);
        $url = '/en/packages/'.$departure->package->slug;

        $this->get($url)->assertOk()->assertSee('MVR 28,500')->assertDontSee('≈ USD');

        $finance = $this->staff(Access::FINANCE);
        $this->actingAs($this->staff(Access::BOOKING_STAFF))->get(ExchangeRateResource::getUrl())->assertForbidden();
        $this->actingAs($finance)->get(ExchangeRateResource::getUrl())->assertOk()->assertSee('No rate for USD');

        Livewire::actingAs($finance)->test(CreateExchangeRate::class)
            ->fillForm(['currency' => 'USD', 'rate' => 15.42, 'effective_on' => today()->toDateString(), 'note' => 'BML selling rate'])
            ->call('create')
            ->assertHasNoFormErrors();

        $line = ExchangeRate::sole();
        $this->assertSame($finance->getKey(), $line->set_by);
        $this->assertSame('MVR', $line->to_currency);

        // The same day again is one line edited, not a second.
        Livewire::actingAs($finance)->test(CreateExchangeRate::class)
            ->fillForm(['currency' => 'USD', 'rate' => 15.50, 'effective_on' => today()->toDateString()])
            ->call('create')
            ->assertHasFormErrors(['effective_on']);

        $this->actingAs($finance)->get(ExchangeRateResource::getUrl())->assertSee('Today 1 USD = 15.4200 MVR');
        $this->get($url)->assertOk()->assertSee('≈ USD 1,848');
    }

    public function test_the_telegram_slip_may_name_its_currency(): void
    {
        config(['telegram.bot_token' => '123456:TEST', 'telegram.bot_username' => 'rihla_test_bot']);
        $photo = UploadedFile::fake()->image('slip.jpg', 400, 600);
        $bytes = (string) file_get_contents((string) $photo->getRealPath());
        Http::fake([
            'api.telegram.org/file/*' => Http::response($bytes),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'file_path' => 'photos/x.jpg']]),
        ]);

        $desk = $this->staff(Access::BOOKING_STAFF);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($desk), 701);
        $booking = $this->booking();
        $send = fn (int $id, string $caption) => $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])
            ->postJson('/api/telegram/webhook', [
                'update_id' => $id,
                'message' => ['message_id' => $id, 'chat' => ['id' => 701, 'type' => 'private'], 'from' => ['id' => 701, 'first_name' => 'T'], 'photo' => [['file_id' => 'big', 'file_unique_id' => 'u'.$id]], 'caption' => $caption],
            ])->assertOk();
        $lastText = fn (): string => (string) collect(Http::recorded())->map(fn (array $pair) => $pair[0])
            ->filter(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))
            ->last()?->data()['text'];

        // No rate: nothing recorded, and the bot says why.
        $send(60, $booking->reference.' 650 USD');
        $this->assertSame(0, Payment::count());
        $this->assertStringContainsString('No exchange rate is known for USD', $lastText());

        $this->rate('USD', 15.42);
        $send(61, $booking->reference.' 650 USD');
        $payment = Payment::sole();
        $this->assertSame(1_002_300, $payment->amount_minor);
        $this->assertSame('USD', $payment->received_currency);
        $this->assertSame(15.42, $payment->exchange_rate);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertStringContainsString('recorded: MVR 10,023 (received USD 650 at 15.4200)', $lastText());

        $send(62, $booking->reference.' 100 XYZ');
        $this->assertStringContainsString('XYZ is not a currency this office takes', $lastText());
        $this->assertSame(1, Payment::count());
    }

    public function test_the_profit_report_reads_the_rate_table(): void
    {
        $booking = $this->booking(3_600_000);
        $departure = $booking->departure;
        DepartureCost::create(['departure_id' => $departure->getKey(), 'category' => DepartureCost::HOTEL, 'description' => 'Makkah hotel', 'currency' => 'USD', 'amount_minor' => 50_000, 'is_per_person' => false, 'status' => DepartureCost::COMMITTED]);

        $profit = JourneyProfit::build($departure, DepartureCost::COMMITTED);
        $this->assertNull($profit->margin(), 'Two currencies and no rate: no single figure.');
        $this->assertStringContainsString('Bookings → Exchange rates', (string) $profit->whyNoSingleFigure());

        $this->rate('USD', 15.42);
        $profit = JourneyProfit::build($departure, DepartureCost::COMMITTED);
        $this->assertSame(3_600_000 - 771_000, $profit->margin()?->minor, 'USD 500 at 15.42 is MVR 7,710.');
        $this->assertStringContainsString('USD 15.4200 from '.today()->format('j M Y'), (string) $profit->rateNote());
    }
}
