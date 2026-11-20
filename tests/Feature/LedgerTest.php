<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\LedgerEntries\Pages\ListLedgerEntries;
use App\Models\Account;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Finance\Books;
use App\Services\Finance\Cashbox;
use App\Support\Access;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Umrah\Models\Departure;
use Tests\TestCase;

/**
 * Accounts and the ledger (companies plan, Phase 3f, from Hijaz): where the
 * money sits, and income or spending that is not a booking's payment or a
 * departure's cost — appended, voided with a reason, never edited.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $name = 'BML current', string $currency = 'MVR', string $kind = Account::BANK): Account
    {
        return Account::query()->create(['name' => $name, 'kind' => $kind, 'currency' => $currency]);
    }

    private function category(string $slug): int
    {
        LedgerCategory::ensureDefaults();

        return (int) LedgerCategory::query()->where('slug', $slug)->value('id');
    }

    public function test_an_accounts_balance_is_its_live_entries(): void
    {
        $bank = $this->account();
        $cashbox = app(Cashbox::class);

        $cashbox->record($bank, LedgerEntry::INCOME, Money::ofMajor(10_000), Carbon::today(), 'Opening balance', ['category_id' => $this->category('opening_balance')]);
        $charge = $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMajor(25), Carbon::today(), 'Transfer fee', ['category_id' => $this->category('bank_charges')]);

        $this->assertSame('MVR 9,975', $bank->balance()->format());

        $cashbox->void($charge, 'Charged to the wrong account');
        $this->assertSame('MVR 10,000', $bank->balance()->format());
        $this->assertNotNull($charge->fresh()->voided_at, 'Voided, and still there.');
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_an_entry_is_never_edited_or_deleted(): void
    {
        $entry = app(Cashbox::class)->record($this->account(), LedgerEntry::INCOME, Money::ofMajor(100), Carbon::today(), 'Fee', ['category_id' => $this->category('fees_income')]);

        try {
            $entry->update(['amount_minor' => 1]);
            $this->fail('An entry was edited.');
        } catch (DeskRefusal) {
        }

        try {
            $entry->delete();
            $this->fail('An entry was deleted.');
        } catch (DeskRefusal) {
        }

        app(Cashbox::class)->void($entry, 'Wrong');

        $this->expectException(DeskRefusal::class);
        $entry->fresh()?->forceFill(['void_reason' => 'Changed my mind'])->save();
    }

    public function test_a_transfer_is_a_pair_in_two_currencies_and_voids_as_one(): void
    {
        $bank = $this->account();
        $float = $this->account('Makkah float', 'SAR', Account::LEADER_CASH);
        app(Cashbox::class)->record($bank, LedgerEntry::INCOME, Money::ofMajor(50_000), Carbon::today(), 'Opening balance', ['category_id' => $this->category('opening_balance')]);

        [$out, $in] = app(Cashbox::class)->transfer($bank, $float, Money::ofMajor(4_100), Money::ofMajor(1_000, 'SAR'), Carbon::today(), 'Float for the Makkah week');

        $this->assertSame('MVR 45,900', $bank->balance()->format());
        $this->assertSame('SAR 1,000', $float->balance()->format());
        $this->assertSame($out->group_key, $in->group_key);

        app(Cashbox::class)->void($in, 'Not sent after all');
        $this->assertNotNull($out->fresh()->voided_at, 'Both sides go together.');
        $this->assertSame('MVR 50,000', $bank->balance()->format());
    }

    public function test_the_rules_refuse_a_wrong_entry_before_it_is_written(): void
    {
        $bank = $this->account();
        $cashbox = app(Cashbox::class);

        foreach ([
            fn () => $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMajor(10, 'SAR'), Carbon::today(), 'Wrong currency'),
            fn () => $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMinor(0), Carbon::today(), 'Nothing'),
            fn () => $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMajor(10), Carbon::today(), 'Income category', ['category_id' => $this->category('fees_income')]),
            fn () => $cashbox->record($bank, LedgerEntry::TRANSFER, Money::ofMajor(10), Carbon::today(), 'Half a transfer'),
            fn () => $cashbox->transfer($bank, $bank, Money::ofMajor(10), Money::ofMajor(10), Carbon::today(), 'To itself'),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} was written.");
            } catch (DeskRefusal) {
            }
        }

        $this->assertSame(0, LedgerEntry::query()->count());
    }

    public function test_income_and_spending_reach_the_books_and_a_transfer_does_not(): void
    {
        $bank = $this->account();
        $float = $this->account('Office cash', 'MVR', Account::CASH);
        $departure = Departure::factory()->withSeats(10)->create();
        $cashbox = app(Cashbox::class);

        $cashbox->record($bank, LedgerEntry::INCOME, Money::ofMajor(2_000), Carbon::today(), 'Commission from the airline', ['category_id' => $this->category('commission_income')]);
        $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMajor(300), Carbon::today(), 'Printer ink', ['category_id' => $this->category('office')]);
        $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMajor(150), Carbon::today(), 'Courier for the passports', ['category_id' => $this->category('other_expense'), 'departure_id' => $departure->id]);
        $cashbox->transfer($bank, $float, Money::ofMajor(500), Money::ofMajor(500), Carbon::today(), 'Petty cash');
        $voided = $cashbox->record($bank, LedgerEntry::EXPENSE, Money::ofMajor(999), Carbon::today(), 'Typed twice', ['category_id' => $this->category('office')]);
        $cashbox->void($voided, 'Typed twice');

        $company = app(Books::class)->cashBook(null);
        $this->assertSame(['in' => 200_000, 'out' => 30_000], $company['totals']['MVR']);

        $onTheDeparture = app(Books::class)->cashBook($departure);
        $this->assertSame(['in' => 0, 'out' => 15_000], $onTheDeparture['totals']['MVR']);

        $month = app(Books::class)->monthly(1)[0];
        $this->assertSame(200_000, $month['in']['MVR']);
        $this->assertSame(45_000, $month['out']['MVR']);
    }

    public function test_finance_keeps_the_ledger_and_a_reader_of_the_books_only_reads_it(): void
    {
        $finance = User::factory()->create()->assignRole(Access::FINANCE);
        $this->assertTrue($finance->can('ledger.manage'));

        Livewire::actingAs($finance)->test(ListAccounts::class)
            ->callAction('create', data: ['name' => 'BML current', 'kind' => Account::BANK, 'currency' => 'MVR', 'account_number' => '7730000012345', 'is_active' => true, 'sort_order' => 0])
            ->assertHasNoActionErrors();

        $bank = Account::query()->sole();
        $this->assertSame('•••••••••2345', $bank->maskedNumber());
        $this->assertStringNotContainsString('7730000012345', (string) DB::table('accounts')->value('account_number'), 'Stored encrypted.');

        $ledger = Livewire::actingAs($finance)->test(ListLedgerEntries::class);
        $ledger->callAction('income', data: [
            'account_id' => $bank->id, 'amount' => 1_000, 'occurred_on' => now()->toDateString(),
            'category_id' => $this->category('opening_balance'), 'description' => 'Opening balance',
        ])->assertHasNoActionErrors();

        $entry = LedgerEntry::query()->sole();
        $ledger->callTableAction('void', $entry, data: ['reason' => 'Wrong account'])->assertNotified('Voided');
        $this->assertSame('Wrong account', $entry->fresh()->void_reason);

        // A reader of the books sees the ledger and none of its buttons.
        $reader = User::factory()->create();
        $reader->givePermissionTo(['admin.access', 'books.view']);
        Livewire::actingAs($reader)->test(ListLedgerEntries::class)
            ->assertActionHidden('income')
            ->assertActionHidden('transfer');
    }
}
