<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Phase 1b of docs/UMRAH_COMPANIES_PLAN.md: Rihla's own surfaces see Rihla's
 * Umrah records only, and a record of another company is refused even when
 * it is reached some other way.
 *
 * The full cross-company sweep (every page, file, PDF, list, export, portal
 * link and bot query) is Phase 1f. These pin the two walls it relies on:
 * the global scope and the gate.
 */
class CompanyWallTest extends TestCase
{
    use RefreshDatabase;

    private function coral(): Partner
    {
        return Partner::factory()->create(['name' => 'Coral Umrah', 'kind' => Partner::KIND_AGENCY]);
    }

    private function theirBooking(Partner $company): Booking
    {
        return app(CompanyContext::class)->as($company->id, fn () => Booking::factory()->create());
    }

    public function test_rihlas_queries_never_see_another_companys_records(): void
    {
        $ours = Booking::factory()->create();
        $theirs = $this->theirBooking($this->coral());

        $this->assertSame([$ours->id], Booking::pluck('id')->all());
        $this->assertNull(Booking::find($theirs->id), 'A record reached by id is scoped too.');
        $this->assertSame(1, Customer::count());

        // Through a relation and through a subquery, not only a plain query.
        $this->assertNull($theirs->customer()->first());
        $this->assertSame(0, Customer::whereHas('bookings', fn ($q) => $q->whereKey($theirs->id))->count());
    }

    public function test_the_other_company_sees_its_own_and_not_rihlas(): void
    {
        $coral = $this->coral();
        $ours = Booking::factory()->create();
        $theirs = $this->theirBooking($coral);

        $seen = app(CompanyContext::class)->as($coral->id, fn () => Booking::pluck('id')->all());

        $this->assertSame([$theirs->id], $seen);
        $this->assertNotContains($ours->id, $seen);
    }

    public function test_a_join_across_two_company_tables_is_not_ambiguous(): void
    {
        $booking = Booking::factory()->create();

        $rows = Booking::query()
            ->join('departures', 'departures.id', '=', 'bookings.departure_id')
            ->pluck('bookings.id')
            ->all();

        $this->assertSame([$booking->id], $rows);
    }

    public function test_another_companys_booking_page_is_not_found_even_for_a_super_admin(): void
    {
        $theirs = $this->theirBooking($this->coral());
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->actingAs($admin)
            ->get(BookingResource::getUrl('edit', ['record' => $theirs->id]))
            ->assertNotFound();
    }

    public function test_the_gate_refuses_another_companys_record_before_the_super_admin_grant(): void
    {
        $coral = $this->coral();
        $theirs = $this->theirBooking($coral);
        $ours = Booking::factory()->create();
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        $this->assertTrue(Gate::forUser($admin)->allows('update', $ours));
        $this->assertFalse(Gate::forUser($admin)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $theirs));

        // Their own context lets it through to the normal checks.
        $this->assertTrue(app(CompanyContext::class)->as($coral->id, fn () => Gate::forUser($admin)->allows('update', $theirs)));
    }

    public function test_rihlas_operator_tools_see_every_company(): void
    {
        Booking::factory()->create();
        $this->theirBooking($this->coral());

        $this->assertSame(2, app(CompanyContext::class)->acrossEveryCompany(fn () => Booking::count()));
        $this->assertSame(1, Booking::count(), 'And the scope comes straight back afterwards.');
    }

    public function test_a_deletion_request_reaches_a_customer_of_any_company(): void
    {
        $coral = $this->coral();
        $theirs = app(CompanyContext::class)->as($coral->id, fn () => Customer::factory()->create(['name' => 'Mariyam Coral']));

        // Run from Rihla's console, where the scope would otherwise hide them.
        $this->artisan('data:forget', ['customer' => $theirs->getKey()])->assertSuccessful();

        $this->assertNotSame('Mariyam Coral', app(CompanyContext::class)->as($coral->id, fn () => Customer::find($theirs->id)?->name));
    }
}
