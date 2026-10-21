<?php

namespace Tests\Feature;

use App\Filament\Pages\Telegram;
use App\Models\TelegramLink;
use App\Models\TelegramLinkCode;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff Telegram bot — batch A4 from Hijaz. Linking, the webhook's
 * gates, and the plain commands; the actions are in TelegramActionsTest.
 */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telegram.bot_token' => '123456:TEST', 'telegram.bot_username' => 'rihla_test_bot']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'rihla_test_bot']])]);
    }

    /** @param  array<string, mixed>  $from */
    private function update(int $id, int $chat, string $text, array $from = []): array
    {
        return [
            'update_id' => $id,
            'message' => [
                'message_id' => $id,
                'chat' => ['id' => $chat, 'type' => 'private'],
                'from' => array_merge(['id' => $chat, 'first_name' => 'Ali', 'username' => 'ali'], $from),
                'text' => $text,
            ],
        ];
    }

    private function deliver(array $update, ?string $secret = null)
    {
        return $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret ?? Bot::webhookSecret(),
        ]);
    }

    /** @return list<string> */
    private function sentTexts(): array
    {
        $texts = [];

        Http::assertSent(function ($request) use (&$texts): bool {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = (string) $request['text'];
            }

            return true;
        });

        return $texts;
    }

    public function test_the_webhook_is_off_without_a_token(): void
    {
        config(['telegram.bot_token' => null]);

        $this->deliver($this->update(1, 10, '/help'))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_webhook_refuses_the_wrong_secret(): void
    {
        $this->deliver($this->update(1, 10, '/help'), 'wrong')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_stranger_is_told_how_to_link_once_a_day(): void
    {
        $this->deliver($this->update(1, 10, 'hello'))->assertOk();
        $this->deliver($this->update(2, 10, 'hello again'))->assertOk();

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('sign in to the staff panel', $texts[0]);
        $this->assertSame(2, TelegramUpdate::query()->where('kind', 'stranger')->count());
    }

    public function test_a_retried_delivery_is_answered_once(): void
    {
        $this->deliver($this->update(7, 10, 'hello'))->assertOk();
        $this->deliver($this->update(7, 10, 'hello'))->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, TelegramUpdate::count());
        $this->assertCount(1, $this->sentTexts());
    }

    public function test_a_code_from_the_panel_links_the_chat_to_the_account(): void
    {
        $user = User::factory()->create(['name' => 'Aminath'])->assignRole(Access::OPERATIONS_MANAGER);
        $code = app(Linker::class)->issueCode($user);

        $this->assertNotNull(TelegramLinkCode::query()->where('code_hash', hash('sha256', $code))->first(), 'Hashed at rest.');

        $this->deliver($this->update(1, 555, "/start {$code}"))->assertOk();

        $link = TelegramLink::query()->active()->where('chat_id', 555)->firstOrFail();
        $this->assertTrue($link->user->is($user));
        $this->assertSame('ali', $link->telegram_username);
        $this->assertSame('Ali', $link->telegram_name);
        $this->assertStringContainsString('Linked to <b>Aminath</b>', $this->sentTexts()[0]);
        $this->assertStringContainsString('/slips', $this->sentTexts()[0], 'The help that follows is for this role.');

        // Used once.
        $this->deliver($this->update(2, 556, "/start {$code}"))->assertOk();
        $this->assertFalse(TelegramLink::query()->active()->where('chat_id', 556)->exists());
        $this->assertStringContainsString('not valid any more', $this->sentTexts()[1]);
    }

    public function test_an_expired_code_or_an_account_that_may_not_link_does_not_link(): void
    {
        $user = User::factory()->create()->assignRole(Access::BOOKING_STAFF);
        $code = app(Linker::class)->issueCode($user);
        $this->travel(11)->minutes();

        $this->deliver($this->update(1, 555, "/start {$code}"))->assertOk();
        $this->assertSame(0, TelegramLink::count());

        // The scholar reviews articles and has no operations to be told about.
        $scholar = User::factory()->create()->assignRole(Access::SCHOLAR);
        $this->assertFalse($scholar->can('telegram.link'));
        $code = app(Linker::class)->issueCode($scholar);
        $this->deliver($this->update(2, 557, "/start {$code}"))->assertOk();
        $this->assertSame(0, TelegramLink::count());

        // A code is for one chat; a second code replaces the first.
        $first = app(Linker::class)->issueCode($user);
        $second = app(Linker::class)->issueCode($user);
        $this->deliver($this->update(3, 558, "/start {$first}"))->assertOk();
        $this->assertSame(0, TelegramLink::count());
        $this->deliver($this->update(4, 558, "/start {$second}"))->assertOk();
        $this->assertSame(1, TelegramLink::count());
    }

    public function test_a_new_link_replaces_the_old_one_for_that_account_and_that_chat(): void
    {
        $linker = app(Linker::class);
        $aminath = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
        $hassan = User::factory()->create()->assignRole(Access::FINANCE);

        $linker->redeem($linker->issueCode($aminath), 100);
        $linker->redeem($linker->issueCode($aminath), 200);
        $this->assertSame(200, $linker->linkForChat(200)?->chat_id);
        $this->assertNull($linker->linkForChat(100), 'One account, one chat.');

        $linker->redeem($linker->issueCode($hassan), 200);
        $this->assertTrue($linker->linkForChat(200)?->user->is($hassan), 'One chat, one account.');
        $this->assertNull($aminath->telegramLink()->first());
    }

    public function test_a_linked_chat_gets_help_me_and_today(): void
    {
        $user = User::factory()->create(['name' => 'Aminath', 'email' => 'aminath@example.invalid'])->assignRole(Access::FINANCE);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 300);

        $this->deliver($this->update(1, 300, '/help'))->assertOk();
        $this->deliver($this->update(2, 300, '/me'))->assertOk();
        $this->deliver($this->update(3, 300, '/today'))->assertOk();
        $this->deliver($this->update(4, 300, '/nonsense'))->assertOk();
        $this->deliver($this->update(5, 300, '/HELP@rihla_test_bot'))->assertOk();

        $texts = $this->sentTexts();
        $this->assertStringContainsString('/slips', $texts[0]);
        $this->assertStringNotContainsString('/checklist', $texts[0], 'Finance holds no checklist permission, so it is not offered one.');
        $this->assertStringContainsString('aminath@example.invalid', $texts[1]);
        $this->assertStringContainsString('Finance', $texts[1]);
        $this->assertStringContainsString('Good morning, Aminath', $texts[2]);
        $this->assertStringContainsString('Nothing is waiting for you', $texts[2]);
        $this->assertStringContainsString("don't know that one", $texts[3]);
        $this->assertStringContainsString('/slips', $texts[4], 'A command addressed to the bot by name still works.');

        $this->assertNotNull(TelegramLink::query()->where('chat_id', 300)->value('last_seen_at'));
        $this->assertSame('/help', TelegramUpdate::query()->where('update_id', 1)->value('command'));
    }

    public function test_unlink_stops_the_chat_acting_as_the_account(): void
    {
        $user = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 300);

        $this->deliver($this->update(1, 300, '/unlink'))->assertOk();
        $this->assertNull(app(Linker::class)->linkForChat(300));

        $this->deliver($this->update(2, 300, '/today'))->assertOk();
        $this->assertStringContainsString('sign in to the staff panel', $this->sentTexts()[1]);
    }

    public function test_the_daily_summary_goes_to_linked_staff_only(): void
    {
        $linker = app(Linker::class);
        $linker->redeem($linker->issueCode(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER)), 400);
        $linker->redeem($linker->issueCode(User::factory()->create()->assignRole(Access::FINANCE)), 401);
        $gone = User::factory()->create()->assignRole(Access::FINANCE);
        $linker->redeem($linker->issueCode($gone), 402);
        $linker->revokeFor($gone);

        $this->artisan('telegram:daily')->expectsOutputToContain('Sent 2 summaries.')->assertSuccessful();

        $chats = [];
        Http::assertSent(function ($request) use (&$chats): bool {
            $chats[] = $request['chat_id'];

            return true;
        });
        $this->assertSame([400, 401], $chats);

        config(['telegram.bot_token' => null]);
        $this->artisan('telegram:daily')->assertSuccessful();
    }

    public function test_the_webhook_command_registers_this_site_with_the_secret(): void
    {
        config(['app.url' => 'https://rihla.mv']);

        $this->artisan('telegram:webhook')->expectsOutputToContain('Webhook set to https://rihla.mv/api/telegram/webhook')->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://rihla.mv/api/telegram/webhook'
            && $request['secret_token'] === Bot::webhookSecret());

        config(['app.url' => 'http://localhost']);
        $this->artisan('telegram:webhook')->assertFailed();
    }

    public function test_the_webhook_command_is_a_no_op_without_a_token(): void
    {
        config(['telegram.bot_token' => null]);

        $this->artisan('telegram:webhook')->expectsOutputToContain('not set')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_panel_page_issues_a_code_and_a_deep_link(): void
    {
        $user = User::factory()->create()->assignRole(Access::BOOKING_STAFF);

        Livewire::actingAs($user)->test(Telegram::class)
            ->assertSee('Not linked')
            ->callAction('issue')
            ->assertSet('deepLink', fn (?string $link): bool => is_string($link) && str_starts_with($link, 'https://t.me/rihla_test_bot?start='))
            ->assertSee('Your link code');

        $code = TelegramLinkCode::query()->where('user_id', $user->getKey())->firstOrFail();
        $this->assertTrue($code->isUsable());

        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 900, ['name' => 'Ali on the phone']);

        Livewire::actingAs($user)->test(Telegram::class)
            ->assertSee('Linked')
            ->assertSee('Ali on the phone')
            ->callAction('unlink');

        $this->assertNull($user->fresh()->telegramLink()->first());

        // The scholar has no page.
        $this->actingAs(User::factory()->create()->assignRole(Access::SCHOLAR))->get(Telegram::getUrl())->assertForbidden();
    }

    public function test_the_panel_page_says_so_when_the_bot_is_off(): void
    {
        config(['telegram.bot_token' => null]);

        Livewire::actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->test(Telegram::class)
            ->assertSee('not set up yet')
            ->assertActionHidden('issue');
    }
}
