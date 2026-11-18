<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\Reading\Duplicates;
use App\Services\Operations\Cards;
use App\Support\Access;
use App\Support\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        // In their own context the record is no longer refused as another
        // company's, and the normal checks decide, from each person's role
        // *at that company* (Phase 2d): their own booking staff may, and
        // Rihla's Super Admin, who works there in no role, may not.
        $theirStaff = User::factory()->create();
        DB::table('company_memberships')->insert([
            'company_id' => $coral->id, 'user_id' => $theirStaff->id, 'role' => Access::BOOKING_STAFF,
            'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inTheirs = fn (User $user): bool => app(CompanyContext::class)->as($coral->id, fn () => Gate::forUser($user)->allows('update', $theirs));
        $this->assertTrue($inTheirs($theirStaff));
        $this->assertFalse($inTheirs($admin));
    }

    public function test_rihlas_operator_tools_see_every_company(): void
    {
        Booking::factory()->create();
        $this->theirBooking($this->coral());

        $this->assertSame(2, app(CompanyContext::class)->acrossEveryCompany(fn () => Booking::count()));
        $this->assertSame(1, Booking::count(), 'And the scope comes straight back afterwards.');
    }

    public function test_one_login_can_be_a_customer_of_two_companies_but_twice_of_none(): void
    {
        $coral = $this->coral();
        $user = User::factory()->create();

        $ours = Customer::factory()->create(['user_id' => $user->id]);
        $theirs = app(CompanyContext::class)->as($coral->id, fn () => Customer::factory()->create(['user_id' => $user->id]));

        $this->assertNotSame($ours->id, $theirs->id);

        $this->expectException(UniqueConstraintViolationException::class);
        Customer::factory()->create(['user_id' => $user->id]);
    }

    public function test_another_companys_card_does_not_resolve(): void
    {
        $member = app(CompanyContext::class)->as($this->coral()->id, function (): BookingTraveller {
            $booking = Booking::factory()->create();
            $traveller = Traveller::create(['customer_id' => $booking->customer_id, 'full_name' => 'Aminath Ali']);

            return BookingTraveller::create([
                'booking_id' => $booking->id, 'traveller_id' => $traveller->id,
                'occupancy' => 'double', 'pax_type' => 'adult', 'amount_minor' => 100,
            ]);
        });
        $card = app(CompanyContext::class)->as($member->company_id, fn () => app(Cards::class)->issue($member, Card::LUGGAGE));

        $this->assertNotNull(app(CompanyContext::class)->as($member->company_id, fn () => app(Cards::class)->resolve($card->qr_token)));
        $this->assertNull(app(Cards::class)->resolve($card->qr_token), 'Rihla scanning their tag finds nobody.');
        $this->assertNull(app(Cards::class)->resolve($card->serial));
    }

    public function test_the_duplicate_id_check_compares_within_the_company(): void
    {
        $coral = $this->coral();
        $customer = app(CompanyContext::class)->as($coral->id, fn () => Customer::factory()->create());
        app(CompanyContext::class)->as($coral->id, fn () => Traveller::create([
            'customer_id' => $customer->id, 'full_name' => 'Aminath Ali', 'national_id' => 'A123456',
        ]));

        $this->assertCount(0, Duplicates::find('A123456', null, null, null), "Their pilgrim is not Rihla's duplicate.");
        $this->assertCount(1, app(CompanyContext::class)->as($coral->id, fn () => Duplicates::find('A123456', null, null, null)));
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
