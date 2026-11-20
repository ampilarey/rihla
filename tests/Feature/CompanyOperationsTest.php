<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Notice;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\SlipVault;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyFeatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Telegram\Models\TelegramLink;
use Modules\Telegram\Services\Bot;
use Modules\Telegram\Services\Linker;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Traveller;
use Modules\Umrah\Services\Booking\SeatAllocator;
use Modules\Umrah\Services\Documents\DocumentWallet;
use Tests\TestCase;

/**
 * Phase 1d of docs/UMRAH_COMPANIES_PLAN.md: files, scheduled work and the
 * Telegram bot each keep to one company.
 */
class CompanyOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        $this->coral = Partner::factory()->create(['name' => 'Coral Umrah', 'kind' => Partner::KIND_AGENCY]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asCoral(callable $callback): mixed
    {
        return app(CompanyContext::class)->as($this->coral->id, $callback);
    }

    private function scan(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 scan');
    }

    public function test_another_companys_scans_and_slips_sit_in_their_own_folder(): void
    {
        $ours = app(DocumentWallet::class)->store(Traveller::factory()->create(), $this->scan(), 'travel', 'passport');
        $theirs = $this->asCoral(fn () => app(DocumentWallet::class)->store(Traveller::factory()->create(), $this->scan(), 'travel', 'passport'));

        // Rihla's live scans stay exactly where they always were.
        $this->assertStringStartsWith('documents/'.$ours->document_id.'/', $ours->path);
        $this->assertStringStartsWith("documents/company-{$this->coral->id}/{$theirs->document_id}/", $theirs->path);

        $slip = $this->asCoral(fn () => app(SlipVault::class)->attach(
            Payment::factory()->create(),
            UploadedFile::fake()->createWithContent('slip.pdf', '%PDF-1.4 slip'),
        ));
        $this->assertStringStartsWith("payment-slips/company-{$this->coral->id}/", (string) $slip->slip_path);
    }

    public function test_seat_holds_lapse_in_every_company(): void
    {
        $departures = [
            Departure::factory()->withSeats(4)->create(),
            $this->asCoral(fn () => Departure::factory()->withSeats(4)->create()),
        ];
        app(SeatAllocator::class)->hold($departures[0], 3, expiresAt: now()->addMinutes(15));
        $this->asCoral(fn () => app(SeatAllocator::class)->hold($departures[1], 2, expiresAt: now()->addMinutes(15)));

        $this->travel(16)->minutes();

        // Run from Rihla's console, as the scheduler does.
        $this->artisan('bookings:expire-holds')
            ->expectsOutputToContain('Released 5 seat(s) across 2 departure(s).')
            ->assertSuccessful();

        $this->assertSame(0, $departures[0]->fresh()->capacity_held);
        $this->assertSame(0, $this->asCoral(fn () => $departures[1]->fresh()?->capacity_held));
    }

    public function test_the_sweep_tells_each_company_about_its_own_bookings(): void
    {
        $booking = $this->asCoral(function (): Booking {
            $departure = Departure::factory()->withSeats(20)->create([
                'date_start' => now()->addDays(9), 'date_end' => now()->addDays(23),
            ]);
            $customer = Customer::factory()->create();
            $booking = Booking::factory()->create(['customer_id' => $customer->id, 'departure_id' => $departure->id]);
            $booking->forceFill(['status' => Booking::CONFIRMED])->save();
            $booking->travellers()->create(['traveller_id' => Traveller::factory()->for($customer)->create()->id, 'occupancy' => 'quad']);

            return $booking;
        });

        $this->artisan('notices:sweep')->expectsOutputToContain('Document needed: 1')->assertSuccessful();

        $notices = $this->asCoral(fn () => Notice::with('noticeable')->get());
        $this->assertNotEmpty($notices);
        foreach ($notices as $notice) {
            $this->assertSame($this->coral->id, $notice->company_id);
            $this->assertTrue($booking->is($notice->noticeable));
        }
        $this->assertSame(0, Notice::count(), "Nothing of theirs is on Rihla's board.");
    }

    public function test_a_chat_linked_to_another_company_is_a_stranger_to_rihlas_bot(): void
    {
        config(['telegram.bot_token' => '123456:TEST', 'telegram.bot_username' => 'rihla_test_bot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        // A member of Coral's team, with Coral's bot paid for: a Rihla role
        // alone no longer answers inside a company (security review).
        $this->coral->forceFill(['features' => [CompanyFeatures::TELEGRAM => CompanyFeatures::ON]])->save();
        $user = User::factory()->create();
        DB::table('company_memberships')->insert(['company_id' => $this->coral->id, 'user_id' => $user->id, 'role' => Access::OPERATIONS_MANAGER, 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->asCoral(fn () => app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 4242));
        $this->assertSame($this->coral->id, $this->asCoral(fn () => TelegramLink::sole()->company_id));

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 1,
            'message' => ['message_id' => 1, 'chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242, 'first_name' => 'T'], 'text' => '/today'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();

        // Rihla's bot answers as it does any chat that has not linked: with
        // how to link, and nothing about anybody's departures.
        Http::assertSent(fn ($request) => str_contains((string) json_encode($request->data()), 'link'));
        Http::assertNotSent(fn ($request) => str_contains((string) json_encode($request->data()), 'Departure'));
    }
}
