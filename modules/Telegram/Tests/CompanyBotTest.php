<?php

namespace Modules\Telegram\Tests;

use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Companies\Filament\Pages\CompanySettings;
use Modules\Companies\Models\CompanyMembership;
use Modules\Companies\Services\AddCompany;
use Modules\Telegram\Models\TelegramLink;
use Modules\Telegram\Services\Bot;
use Modules\Telegram\Services\Linker;
use Tests\TestCase;

/**
 * Phase 2d of docs/UMRAH_COMPANIES_PLAN.md (decision 7): each company's own
 * Telegram bot, answering for that company only.
 */
class CompanyBotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '777000:CORALcoralCORALcoralCORALcoralCORAL';

    private Partner $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['telegram.bot_token' => '123456:RIHLArihlaRIHLArihlaRIHLArihla', 'telegram.bot_username' => 'rihla_bot']);
        $this->company = app(AddCompany::class)('Coral Umrah', 'Aishath Coral', 'owner@coral.example');
        $this->owner = User::where('email', 'owner@coral.example')->sole();
    }

    private function inPanel(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('company');
        Filament::setTenant($this->company);
        app(CompanyContext::class)->use($this->company->id);
    }

    private function connected(): void
    {
        $this->company->forceFill(['telegram_bot_token' => self::TOKEN])->save();
    }

    /** @param  array<string, mixed>  $message */
    private function deliver(int $updateId, array $message, ?string $secret = null): TestResponse
    {
        $secret ??= app(CompanyContext::class)->as($this->company->id, fn (): string => Bot::webhookSecret());
        $this->app->forgetScopedInstances();

        return $this->postJson('/api/telegram/company/coral-umrah/webhook', [
            'update_id' => $updateId,
            'message' => $message + ['message_id' => $updateId, 'chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242, 'first_name' => 'T']],
        ], ['X-Telegram-Bot-Api-Secret-Token' => $secret]);
    }

    public function test_the_owner_connects_a_bot_and_its_webhook_is_this_companys(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->inPanel();

        Livewire::test(CompanySettings::class)
            ->fillForm(['name' => 'Coral Umrah', 'telegram_bot_token' => self::TOKEN])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.telegram_bot_token', null);

        $this->assertSame(self::TOKEN, $this->company->fresh()?->telegram_bot_token);
        $this->assertNotSame(self::TOKEN, DB::table('partners')->where('id', $this->company->id)->value('telegram_bot_token'), 'The token is ciphertext at rest.');
        $this->assertArrayNotHasKey('telegram_bot_token', $this->company->fresh()?->toArray() ?? [], 'Never serialised.');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bot'.self::TOKEN.'/setWebhook')
            && str_ends_with((string) $request['url'], '/api/telegram/company/coral-umrah/webhook')
            && $request['secret_token'] === app(CompanyContext::class)->as($this->company->id, fn (): string => Bot::webhookSecret()));
    }

    public function test_a_token_telegram_refuses_is_not_kept(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);
        $this->inPanel();

        Livewire::test(CompanySettings::class)
            ->fillForm(['name' => 'Coral Umrah', 'telegram_bot_token' => self::TOKEN])
            ->call('save');

        $this->assertNull($this->company->fresh()?->telegram_bot_token);
    }

    public function test_the_companys_webhook_answers_only_for_its_bot(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        // No bot yet: as if there were no webhook at all.
        $this->deliver(1, ['text' => '/start'])->assertNotFound();

        $this->connected();

        // Rihla's secret opens nobody else's webhook.
        $this->deliver(2, ['text' => '/start'], Bot::webhookSecret())->assertForbidden();

        // A stranger is told how to link, by the company's own bot.
        $this->deliver(3, ['text' => '/start'])->assertOk();
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bot'.self::TOKEN.'/sendMessage'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/bot123456:'));
    }

    public function test_a_linked_member_is_answered_with_their_role_at_the_company(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->connected();
        $finance = User::factory()->create();
        CompanyMembership::create(['company_id' => $this->company->id, 'user_id' => $finance->id, 'role' => Access::FINANCE, 'accepted_at' => now()]);

        app(CompanyContext::class)->as($this->company->id, function () use ($finance): void {
            app(Linker::class)->redeem(app(Linker::class)->issueCode($finance), 4242);
        });
        $this->assertSame($this->company->id, app(CompanyContext::class)->acrossEveryCompany(fn () => TelegramLink::sole()->company_id));

        $this->deliver(10, ['text' => '/slips'])->assertOk();

        // Finance at Coral may see payments: told there is nothing waiting,
        // not that their account cannot see them.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bot'.self::TOKEN.'/sendMessage')
            && str_contains((string) $request['text'], 'Nothing waiting'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request['text'], 'cannot see payments'));

        // And /me names their role at the company, not "none".
        $this->deliver(11, ['text' => '/me'])->assertOk();
        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'Role: '.Access::FINANCE));
    }

    public function test_two_bots_may_deliver_the_same_update_number(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->connected();

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 555, 'message' => ['message_id' => 1, 'chat' => ['id' => 9, 'type' => 'private'], 'from' => ['id' => 9], 'text' => '/start'],
        ], ['X-Telegram-Bot-Api-Secret-Token' => Bot::webhookSecret()])->assertOk()->assertJsonMissing(['duplicate' => true]);

        $this->deliver(555, ['text' => '/start'])->assertOk()->assertJsonMissing(['duplicate' => true]);

        // And the same number twice from one bot is still a duplicate.
        $this->deliver(555, ['text' => '/start'])->assertOk()->assertJson(['duplicate' => true]);
    }

    public function test_the_morning_summary_goes_out_from_each_companys_own_bot(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->connected();
        $finance = User::factory()->create();
        CompanyMembership::create(['company_id' => $this->company->id, 'user_id' => $finance->id, 'role' => Access::FINANCE, 'accepted_at' => now()]);
        app(CompanyContext::class)->as($this->company->id, fn () => app(Linker::class)->redeem(app(Linker::class)->issueCode($finance), 4242));

        $this->artisan('telegram:daily')->expectsOutputToContain('Sent 1 summaries.')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bot'.self::TOKEN.'/sendMessage') && (int) $request['chat_id'] === 4242);
    }
}
