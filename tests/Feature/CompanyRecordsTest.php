<?php

namespace Tests\Feature;

use App\Exceptions\CompanyMismatch;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Payment;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Stays\Models\Stay;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\BookingTraveller;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Package;
use Modules\Umrah\Models\Traveller;
use Tests\TestCase;

/**
 * Phase 1a of docs/UMRAH_COMPANIES_PLAN.md: every Umrah record carries the
 * company it belongs to, copied from what it hangs off and never mixed.
 *
 * Nothing here scopes a query yet (that is 1b). These tests pin the column,
 * the backfill and the one rule that stops two companies' records being
 * joined in a single row.
 */
class CompanyRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_11_18_100000_give_umrah_records_a_company.php';

    private function rihla(): Partner
    {
        return Partner::rihla() ?? $this->fail('The migration that makes Rihla a partner has not run.');
    }

    private function otherCompany(): Partner
    {
        return Partner::factory()->create(['name' => 'Coral Umrah', 'kind' => Partner::KIND_AGENCY]);
    }

    /** @return list<string> */
    private function migrationTables(): array
    {
        $migration = require self::MIGRATION;

        return (new \ReflectionClassConstant($migration, 'TABLES'))->getValue();
    }

    public function test_every_umrah_table_has_the_column_and_its_model_the_rule(): void
    {
        $tables = $this->migrationTables();
        $this->assertCount(57, $tables);

        $models = [];
        foreach (self::modelFiles() as $class) {
            if (in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                $models[(new $class)->getTable()] = $class;
            }
        }

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'company_id'), "{$table} has no company_id.");
            $this->assertArrayHasKey($table, $models, "No model of {$table} uses BelongsToCompany.");
        }

        // And the reverse: no model claims a company its table cannot hold,
        // including a table added after this migration, by its own.
        foreach ($models as $table => $class) {
            $this->assertTrue(Schema::hasColumn($table, 'company_id'), "{$class} uses BelongsToCompany but {$table} has no company_id.");
        }
    }

    public function test_a_booking_and_everything_under_it_is_rihlas_without_anybody_saying_so(): void
    {
        $booking = Booking::factory()->create();
        $traveller = Traveller::create(['customer_id' => $booking->customer_id, 'full_name' => 'Aminath Ali']);
        $seat = BookingTraveller::create([
            'booking_id' => $booking->id, 'traveller_id' => $traveller->id,
            'occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 100,
        ]);

        $rihla = $this->rihla()->id;
        $this->assertSame($rihla, $booking->departure->package->company_id);
        $this->assertSame($rihla, $booking->departure->company_id);
        $this->assertSame($rihla, $booking->customer->company_id);
        $this->assertSame($rihla, $booking->company_id);
        $this->assertSame($rihla, $traveller->company_id);
        $this->assertSame($rihla, $seat->company_id);
        $this->assertSame($rihla, $booking->company?->id);
    }

    public function test_a_record_made_for_another_company_carries_it_to_its_children(): void
    {
        $coral = $this->otherCompany();

        $departure = app(CompanyContext::class)->as($coral->id, fn () => Departure::factory()->create());

        // Outside the context: the child copies its parent, not the default.
        $this->assertSame($coral->id, app(CompanyContext::class)->as($coral->id, fn () => $departure->package?->company_id));
        $this->assertSame($coral->id, $departure->company_id);
        $this->assertSame($coral->id, $departure->priceTiers()->create([
            'occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 100, 'currency' => 'MVR',
        ])->company_id);
    }

    public function test_a_booking_may_not_join_one_companys_customer_to_anothers_departure(): void
    {
        $coral = $this->otherCompany();
        $theirs = app(CompanyContext::class)->as($coral->id, fn () => Customer::factory()->create());
        $ours = Departure::factory()->create();

        $this->expectException(CompanyMismatch::class);

        Booking::factory()->create(['customer_id' => $theirs->id, 'departure_id' => $ours->id]);
    }

    public function test_a_row_may_not_be_handed_a_company_its_parents_do_not_share(): void
    {
        $coral = $this->otherCompany();
        $customer = Customer::factory()->create();

        $this->expectException(CompanyMismatch::class);

        (new Traveller)->forceFill(['customer_id' => $customer->id, 'full_name' => 'Aminath Ali', 'company_id' => $coral->id])->save();
    }

    public function test_re_pointing_a_record_at_another_companys_parent_is_refused(): void
    {
        $coral = $this->otherCompany();
        $booking = Booking::factory()->create();
        $theirs = app(CompanyContext::class)->as($coral->id, fn () => Customer::factory()->create());

        $this->expectException(CompanyMismatch::class);

        $booking->update(['customer_id' => $theirs->id]);
    }

    public function test_a_records_company_never_changes(): void
    {
        $coral = $this->otherCompany();
        $customer = Customer::factory()->create();

        $this->expectException(CompanyMismatch::class);

        $customer->forceFill(['company_id' => $coral->id])->save();
    }

    public function test_a_parent_that_is_not_an_umrah_record_says_nothing_about_the_company(): void
    {
        // A payment for a stay hangs off a table with no company: it takes
        // the current company rather than failing or staying empty.
        $stay = Stay::factory()->create();
        $payment = Payment::factory()->create(['payable_type' => $stay->getMorphClass(), 'payable_id' => $stay->id]);

        $this->assertSame($this->rihla()->id, $payment->company_id);
    }

    public function test_the_backfill_stamps_every_existing_row_with_the_company_flagged_as_rihla(): void
    {
        // The state a deployed database is in: rows written before the
        // column existed. Seeded with the column gone, then `up()` run on it
        // (AGENTS.md: a fresh-database assertion never sees this state).
        $package = Package::factory()->create();
        $migration = require self::MIGRATION;

        // Rolled back in the order migrate:rollback would: the per-company
        // slugs (1e) and customer login (1c) index this column, and MySQL refuses to
        // drop a column an index still names.
        $later = [
            require __DIR__.'/../../database/migrations/2026_11_19_120000_give_each_company_its_own_bot.php',
            require __DIR__.'/../../database/migrations/2026_11_18_120000_make_public_slugs_per_company.php',
            require __DIR__.'/../../database/migrations/2026_11_18_110000_make_a_customer_login_per_company.php',
        ];
        foreach ($later as $step) {
            $step->down();
        }
        $migration->down();
        $this->assertFalse(Schema::hasColumn('packages', 'company_id'));

        // Rihla's row is found by the flag on the day it runs, not by id.
        DB::table('partners')->where('is_rihla', true)->update(['id' => 77]);

        $migration->up();
        foreach (array_reverse($later) as $step) {
            $step->up();
        }

        $this->assertSame(77, (int) DB::table('packages')->where('id', $package->id)->value('company_id'));
    }
}
