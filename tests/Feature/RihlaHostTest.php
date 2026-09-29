<?php

namespace Tests\Feature;

use App\Models\Partner;
use App\Models\Property;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rihla as a host of its own rooms — §16 Phase 12.5, ADR 0008 decision 3.
 */
class RihlaHostTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require __DIR__.'/../../database/migrations/2026_09_29_120000_create_rihlas_own_host.php';
    }

    public function test_rihla_is_a_host_that_collects_everything_and_pays_itself_nothing(): void
    {
        $rihla = Partner::rihla();

        $this->assertNotNull($rihla);
        $this->assertSame('rihla-travels', $rihla->slug);
        $this->assertTrue($rihla->is_rihla);
        $this->assertSame(Partner::FULL_COLLECTION, $rihla->settlement_model);
        $this->assertSame(0, $rihla->commission_pct);
        $this->assertSame(Partner::VERIFIED, $rihla->verification);
        $this->assertSame(Partner::STATUS_ACTIVE, $rihla->status);
        $this->assertSame(1, Partner::where('is_rihla', true)->count());
    }

    /** A row somebody already made by hand is adopted, not duplicated. */
    public function test_running_it_again_finds_the_row_rather_than_making_another(): void
    {
        DB::table('partners')->where('slug', 'rihla-travels')->update(['is_rihla' => false, 'settlement_model' => 'commission_deposit']);

        $this->migration()->up();

        $this->assertSame(1, Partner::where('slug', 'rihla-travels')->count());
        $this->assertSame(Partner::FULL_COLLECTION, Partner::rihla()?->settlement_model);
    }

    /** A guesthouse that shares the word must never become the company. */
    public function test_a_partner_merely_named_rihla_is_left_alone(): void
    {
        $namesake = Partner::factory()->create(['name' => 'Rihla']);

        $this->migration()->up();

        $this->assertSame('rihla', $namesake->slug);
        $this->assertFalse($namesake->fresh()->is_rihla);
        $this->assertSame(1, Partner::where('is_rihla', true)->count());
    }

    /** Found by the flag, because the test server's scrub rewrites every slug. */
    public function test_rihla_is_still_found_after_the_test_server_scrub(): void
    {
        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $this->assertNotNull(Partner::rihla());
        $this->assertNotSame('rihla-travels', Partner::rihla()->slug);
    }

    /** Rolling back must not take Rihla's own rooms with it. */
    public function test_rolling_back_leaves_a_rihla_that_holds_rooms(): void
    {
        Property::factory()->create(['partner_id' => Partner::rihla()?->id]);

        $this->migration()->down();

        $this->assertNotNull(Partner::rihla());
    }
}
