<?php

namespace Tests\Feature;

use App\Filament\Resources\Notices\Pages\ListNotices;
use App\Mail\NoticeMail;
use App\Models\Customer;
use App\Models\Notice;
use App\Models\NoticeEmail;
use App\Models\User;
use App\Support\Access;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Modules\Travel\Models\TravelRequest;
use Modules\Travel\Services\TravelRequests;
use RuntimeException;
use Tests\TestCase;

/**
 * `notices:email`: each new notice goes to the customer by e-mail as well
 * as onto the staff queue, once, only when the site really sends mail.
 */
class NoticeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00'));
        config(['mail.default' => 'smtp', 'mail.from.address' => 'hello@rihla.mv']);
        Mail::fake();
    }

    private function quotedTrip(?string $email = 'mariyam@example.com', ?Customer $customer = null): TravelRequest
    {
        $requests = app(TravelRequests::class);
        $trip = $requests->submit([
            'wants_flight' => true, 'wants_hotel' => false, 'wants_visa' => false, 'destination' => 'Colombo',
            'depart_on' => '2026-12-20', 'adults' => 1, 'contact_name' => 'Mariyam Saeed', 'contact_phone' => '7795555',
            'contact_email' => $email,
        ], $customer);
        $requests->addOption($trip, 'SriLankan', null, Money::ofMajor(4000, 'MVR'));
        $requests->sendQuote($trip, 3, null);

        return $trip;
    }

    public function test_a_new_notice_is_emailed_once_and_the_queue_says_so(): void
    {
        $trip = $this->quotedTrip();
        $notice = Notice::query()->where('kind', Notice::TRAVEL_QUOTED)->sole();

        $this->artisan('notices:email')->expectsOutputToContain('Sent 1 notice e-mail(s).')->assertSuccessful();
        $this->artisan('notices:email')->expectsOutputToContain('Sent 0 notice e-mail(s).')->assertSuccessful();

        Mail::assertSent(NoticeMail::class, 1);
        Mail::assertSent(NoticeMail::class, fn (NoticeMail $mail): bool => $mail->hasTo('mariyam@example.com')
            && $mail->notice->is($notice)
            && str_contains($mail->render(), $trip->reference)
            && str_contains($mail->render(), 'Rihla'));

        Livewire::actingAs(User::factory()->create()->assignRole(Access::BOOKING_STAFF))
            ->test(ListNotices::class)->call('loadTable')->assertSee('e-mailed');
    }

    public function test_nothing_is_sent_or_recorded_without_real_mail_settings(): void
    {
        config(['mail.default' => 'log']);
        $this->quotedTrip();

        $this->artisan('notices:email')->expectsOutputToContain('Not sending: Email is set to "log"')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, NoticeEmail::count());
    }

    public function test_no_address_handled_or_old_notices_are_left_to_the_queue(): void
    {
        $this->quotedTrip(email: null);
        $handled = $this->quotedTrip('handled@example.com');
        Notice::query()->where('noticeable_id', $handled->id)->firstOrFail()->markHandled();
        $old = $this->quotedTrip('old@example.com');
        Notice::query()->where('noticeable_id', $old->id)->update(['created_at' => now()->subDays(4)]);

        $this->artisan('notices:email')->expectsOutputToContain('Sent 0 notice e-mail(s).')->assertSuccessful();
        Mail::assertNothingSent();
    }

    public function test_a_failure_is_retried_three_times_and_then_left_alone(): void
    {
        $this->quotedTrip();
        Mail::shouldReceive('to')->andThrow(new RuntimeException('Connection refused'));

        foreach (range(1, 4) as $run) {
            $this->artisan('notices:email')->assertSuccessful();
        }

        $record = NoticeEmail::query()->sole();
        $this->assertNull($record->sent_at);
        $this->assertSame(NoticeEmail::MAX_ATTEMPTS, $record->attempts);
        $this->assertSame('Connection refused', $record->last_error);
    }

    public function test_forgetting_the_customer_scrubs_where_it_was_sent(): void
    {
        $customer = Customer::factory()->create();
        $this->quotedTrip('mariyam@example.com', $customer);
        $this->artisan('notices:email')->assertSuccessful();

        $this->artisan('data:forget', ['customer' => (string) $customer->id])->assertSuccessful();

        $this->assertNotSame('mariyam@example.com', DB::table('notice_emails')->value('address'));
    }
}
