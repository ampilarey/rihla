<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BusGroup;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\TelegramLink;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Booking\BookingDesk;
use App\Services\Operations\Buses;
use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\SlipVault;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use App\Support\AuditContext;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the staff bot does beyond answering: the alert when a slip arrives,
 * the two-tap verification, the refusal with a reason, the checklist tick,
 * the lists as files, and a slip sent as a photo.
 */
class TelegramActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        config(['telegram.bot_token' => '123456:TEST', 'telegram.bot_username' => 'rihla_test_bot']);

        // Held in a variable: the fake deletes its file when it goes out of scope.
        $photo = UploadedFile::fake()->image('slip.jpg', 400, 600);
        $bytes = (string) file_get_contents((string) $photo->getRealPath());

        Http::fake([
            'api.telegram.org/file/*' => Http::response($bytes),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'file_path' => 'photos/x.jpg']]),
        ]);
    }

    private function linked(string $role, int $chat): User
    {
        $user = User::factory()->create()->assignRole($role);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), $chat);

        return $user;
    }

    /** @param  array<string, mixed>  $extra */
    private function send(int $id, int $chat, string $text, array $extra = []): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $id,
            'message' => array_merge(['message_id' => $id, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'T'], 'text' => $text], $extra),
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();
    }

    private function tap(int $id, int $chat, string $data, int $messageId = 77): void
    {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => $id,
            'callback_query' => ['id' => 'cq'.$id, 'from' => ['id' => $chat], 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => $chat], 'text' => 'original']],
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk();
    }

    /** @return list<array{method: string, body: array<string, mixed>}> */
    private function calls(): array
    {
        $calls = [];

        Http::assertSent(function ($request) use (&$calls): bool {
            $data = (array) $request->data();

            // A multipart request (sendDocument) comes back as its parts.
            if (array_is_list($data) && isset($data[0]['name'])) {
                $data = collect($data)->mapWithKeys(fn (array $part): array => [(string) $part['name'] => $part['contents'] ?? null])->all();
            }

            $calls[] = ['method' => basename($request->url()), 'body' => $data];

            return true;
        });

        return $calls;
    }

    private function booking(): Booking
    {
        $departure = Departure::factory()->withSeats(40)->create(['date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        $departure->priceTiers()->create(['occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 3_200_000]);
        $customer = Customer::factory()->create(['name' => 'Aminath Ali', 'phone' => '7771234']);
        $traveller = Traveller::factory()->for($customer)->create(['full_name' => 'Aminath Ali', 'passport_number' => 'P1234567', 'date_of_birth' => now()->subYears(40)]);

        return app(BookingDesk::class)->open($departure, $customer, [['traveller_id' => $traveller->getKey(), 'occupancy' => 'double']]);
    }

    private function slipFor(Booking $booking, int $major = 10000): Payment
    {
        $payment = app(BankTransfer::class)->start($booking, Money::ofMajor($major, 'MVR'));
        app(SlipVault::class)->attach($payment, UploadedFile::fake()->image('slip.jpg'));

        return $payment->fresh();
    }

    public function test_finance_hears_of_a_slip_and_verifies_it_in_two_taps(): void
    {
        $finance = $this->linked(Access::FINANCE, 700);
        $this->linked(Access::BOOKING_STAFF, 701);
        $booking = $this->booking();
        $payment = $this->slipFor($booking);

        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);

        // Told, with the buttons; the booking desk is not, since it cannot verify.
        $alerts = collect($this->calls())->filter(fn (array $call): bool => $call['method'] === 'sendMessage' && str_contains((string) ($call['body']['text'] ?? ''), 'Payment to verify'));
        $this->assertSame([700], $alerts->pluck('body.chat_id')->all());
        $this->assertSame('pay.reconcile:'.$payment->getKey(), $alerts->first()['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringContainsString('Slip attached', $alerts->first()['body']['text']);

        // The booking desk tapping the button gets a refusal.
        $this->tap(10, 701, 'pay.reconcile:'.$payment->getKey());
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->fresh()->status);
        $this->assertTrue(collect($this->calls())->contains(fn (array $call): bool => $call['method'] === 'answerCallbackQuery' && str_contains((string) ($call['body']['text'] ?? ''), 'cannot verify')));

        // Finance: first tap asks, second does.
        $this->tap(11, 700, 'pay.reconcile:'.$payment->getKey());
        $confirm = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'editMessageText');
        $this->assertStringContainsString('Verify '.$payment->reference.'?', $confirm['body']['text']);
        $this->assertSame('pay.reconcile.yes:'.$payment->getKey(), $confirm['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->fresh()->status, 'Nothing happens on the first tap.');

        $this->tap(12, 700, 'pay.reconcile.yes:'.$payment->getKey());
        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()->status);
        $this->assertTrue($payment->fresh()->reviewer->is($finance));
        $this->assertSame(1_000_000, $booking->fresh()->paid()->minor);
        $this->assertStringContainsString('verified</b> by '.$finance->name, collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'editMessageText')['body']['text']);

        // As that person: the ledger's own record names them, and the
        // booking's audit row says it was by chat.
        $this->assertTrue($payment->fresh()->transactions()->where('to_status', Payment::SUCCEEDED)->where('user_id', $finance->getKey())->exists());
        $byChat = AuditLog::query()->where('channel', AuditContext::TELEGRAM)->where('user_id', $finance->getKey())->latest('id')->first();
        $this->assertNotNull($byChat, 'The booking\'s paid total changed, as this person, by chat.');

        // A third tap finds nothing waiting.
        $this->tap(13, 700, 'pay.reconcile.yes:'.$payment->getKey());
        $this->assertTrue(collect($this->calls())->contains(fn (array $call): bool => $call['method'] === 'answerCallbackQuery' && str_contains((string) ($call['body']['text'] ?? ''), 'no longer waiting')));
    }

    public function test_refusing_asks_for_the_reason_in_the_next_message(): void
    {
        $this->linked(Access::FINANCE, 700);
        $payment = $this->slipFor($this->booking());

        $this->tap(20, 700, 'pay.refuse:'.$payment->getKey());
        $this->assertSame('pay.refuse', TelegramLink::query()->where('chat_id', 700)->first()->pending['action']);

        $this->send(21, 700, 'Nothing arrived in BML by Friday');
        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame('Nothing arrived in BML by Friday', $payment->fresh()->rejection_reason);
        $this->assertNull(TelegramLink::query()->where('chat_id', 700)->first()->pending);
        $this->assertStringContainsString('marked not received', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);

        // With nothing pending, a plain message is just a message.
        $this->send(22, 700, 'hello?');
        $this->assertStringContainsString("don't know that one", collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);
    }

    public function test_slips_balance_find_and_departures_answer_by_permission(): void
    {
        $this->linked(Access::FINANCE, 700);
        // Visa Staff read bookings and never money.
        $this->linked(Access::VISA_STAFF, 702);
        $booking = $this->booking();
        $payment = $this->slipFor($booking);

        $this->send(30, 700, '/slips');
        $listed = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage' && str_contains((string) ($call['body']['text'] ?? ''), (string) $payment->reference));
        $this->assertSame('pay.reconcile:'.$payment->getKey(), $listed['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        // Visa Staff get neither the list nor a balance.
        $this->send(31, 702, '/slips');
        $this->assertStringContainsString('cannot see payments', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);

        $this->send(32, 702, '/balance '.$booking->reference);
        $this->assertStringContainsString('cannot see money', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);

        $this->send(33, 700, '/balance aminath');
        $text = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text'];
        $this->assertStringContainsString($booking->reference, $text);
        $this->assertStringContainsString('MVR 32,000 total', $text);
        $this->assertStringContainsString('1 payment waiting for Finance', $text);

        $this->send(34, 702, '/find P1234567');
        $text = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text'];
        $this->assertStringContainsString('<b>Aminath Ali</b> · passport P1234567', $text);
        $this->assertStringContainsString($booking->reference, $text);
        $this->assertStringNotContainsString('due', $text, 'No money for a reader without payment.viewAny.');

        $this->send(35, 700, '/find rih-b-'.substr($booking->reference, 6));
        $this->assertStringContainsString('<b>'.$booking->reference.'</b>', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);

        $this->send(36, 700, '/departures');
        $text = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text'];
        $this->assertStringContainsString('#'.$booking->departure_id.' · ', $text);
        $this->assertStringContainsString('1/40 seats', $text);

        $this->send(37, 700, '/departure '.$booking->departure_id);
        $text = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text'];
        $this->assertStringContainsString('Bookings: 1 live', $text);
        $this->assertStringContainsString('of MVR 32,000 received', $text);
    }

    public function test_the_checklist_is_ticked_by_tap_and_the_lists_arrive_as_files(): void
    {
        $ops = $this->linked(Access::OPERATIONS_MANAGER, 700);
        $leader = $this->linked(Access::TOUR_LEADER, 703);
        $booking = $this->booking();
        $booking->transitionTo(Booking::CONFIRMED, 'Paid in cash.');
        $departure = $booking->departure;
        $item = $departure->checklist()->create(['title' => 'Group visa submitted', 'due_on' => now()->subDay(), 'is_blocking' => true]);
        $bus = BusGroup::create(['departure_id' => $departure->getKey(), 'name' => 'Bus 1', 'leader_name' => 'Ahmed']);
        app(Buses::class)->assign($bus, $booking->travellers()->pluck('id')->all());

        $this->send(40, 700, '/checklist '.$departure->getKey());
        $offered = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage');
        $this->assertSame('check.tick:'.$item->getKey(), $offered['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertStringContainsString('⚠ Group visa submitted', $offered['body']['reply_markup']['inline_keyboard'][0][0]['text']);

        $this->tap(41, 700, 'check.tick:'.$item->getKey());
        $this->assertFalse($item->fresh()->isDone(), 'The first tap only asks.');
        $this->tap(42, 700, 'check.tick.yes:'.$item->getKey());
        $this->assertTrue($item->fresh()->isDone());
        $this->assertTrue($item->fresh()->doneBy->is($ops));

        // The leader gets the bus list and the manifest as files, without money.
        $this->send(43, 703, '/bus 1');
        $sent = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendDocument');
        $this->assertNotNull($sent);
        $this->assertStringContainsString('Bus 1', (string) $sent['body']['caption']);
        $this->assertStringContainsString('1 on board', (string) $sent['body']['caption']);

        $this->send(44, 703, '/manifest airline');
        $sent = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendDocument');
        $this->assertStringContainsString('Airline list', (string) $sent['body']['caption']);

        $this->send(45, 703, '/invoice '.$booking->reference);
        $this->assertStringContainsString('cannot see bookings', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);

        $this->send(46, 700, '/invoice '.$booking->reference);
        $sent = collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendDocument');
        $this->assertStringContainsString($booking->reference, (string) $sent['body']['caption']);

        $this->send(47, 700, '/ready '.$departure->getKey());
        $this->assertStringContainsString('⛔', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);
        $leader->refresh();
    }

    public function test_a_slip_photo_with_the_booking_reference_records_the_payment(): void
    {
        $desk = $this->linked(Access::BOOKING_STAFF, 701);
        $this->linked(Access::FINANCE, 700);
        $booking = $this->booking();

        $this->send(50, 701, '', ['photo' => [['file_id' => 'small', 'file_unique_id' => 'u1'], ['file_id' => 'big', 'file_unique_id' => 'u2']], 'caption' => $booking->reference.' 10,000']);

        $payment = Payment::query()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame(Payment::AWAITING_REVIEW, $payment->status);
        $this->assertSame(1_000_000, $payment->amount_minor);
        $this->assertSame(Payment::BANK_TRANSFER, $payment->method);
        $this->assertTrue($payment->hasSlip());
        $this->assertSame($desk->getKey(), $payment->recorded_by);
        $this->assertStringContainsString('recorded: MVR 10,000', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage' && ($call['body']['chat_id'] ?? null) === 701)['body']['text']);

        // Finance was told, the desk was not.
        $alerts = collect($this->calls())->filter(fn (array $call): bool => $call['method'] === 'sendMessage' && str_contains((string) ($call['body']['text'] ?? ''), 'Payment to verify'));
        $this->assertSame([700], $alerts->pluck('body.chat_id')->all());

        // No reference: told what to write. No amount and nothing owed: told to say how much.
        $this->send(51, 701, '', ['photo' => [['file_id' => 'big', 'file_unique_id' => 'u3']], 'caption' => 'here is the slip']);
        $this->assertStringContainsString('Put the booking reference in the caption', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);
        $this->assertSame(1, Payment::count());

        // A file of the wrong kind is refused.
        $this->send(52, 701, '', ['document' => ['file_id' => 'doc', 'file_name' => 'slip.zip', 'mime_type' => 'application/zip'], 'caption' => $booking->reference.' 500']);
        $this->assertStringContainsString('as a photo, or as a PDF', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);
        $this->assertSame(1, Payment::count());

        // Pilgrim Support may not record money.
        $this->linked(Access::PILGRIM_SUPPORT, 702);
        $this->send(53, 702, '', ['photo' => [['file_id' => 'big', 'file_unique_id' => 'u4']], 'caption' => $booking->reference.' 500']);
        $this->assertStringContainsString('cannot record payments', collect($this->calls())->last(fn (array $call): bool => $call['method'] === 'sendMessage')['body']['text']);
        $this->assertSame(1, Payment::count());
    }
}
