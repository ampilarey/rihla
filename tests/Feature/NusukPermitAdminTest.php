<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\RelationManagers\DeparturesRelationManager;
use App\Filament\Resources\Permits\NusukPermitResource;
use App\Filament\Resources\Permits\Pages\ListNusukPermits;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\NusukPermit;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use App\Support\TravelReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screens the Nusuk workflow is worked through.
 *
 * Rendered rather than status-checked. A Filament page answers 200 while a
 * column closure throws inside its own Livewire request — the lesson the
 * Pulse dashboard taught, written into AGENTS.md — so every test here draws
 * the page and reads what came out.
 */
class NusukPermitAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function departure(bool $prerequisitesMet = true): Departure
    {
        return Departure::factory()->withSeats(10)->create([
            'date_start' => now()->addDays(60),
            'date_end' => now()->addDays(74),
            'nusuk_accommodation_recorded_at' => $prerequisitesMet ? now() : null,
            'nusuk_transport_recorded_at' => $prerequisitesMet ? now() : null,
        ]);
    }

    private function booking(?Departure $departure = null): Booking
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => ($departure ?? $this->departure())->getKey(),
            'seats' => 1,
        ]);

        $booking->travellers()->create([
            'traveller_id' => Traveller::factory()->for($customer)->create([
                'full_name' => 'Aishath Nazima',
            ])->getKey(),
            'occupancy' => 'quad',
            'is_lead' => true,
        ]);

        return $booking->refresh();
    }

    private function permit(?Booking $booking = null, string $kind = NusukPermit::UMRAH): NusukPermit
    {
        $booking ??= $this->booking();

        return NusukPermit::factory()->create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $booking->travellers->first()->traveller_id,
            'kind' => $kind,
        ]);
    }

    // ── Who may look ──────────────────────────────────────────────────────

    public function test_visa_staff_can_list_permits(): void
    {
        $this->permit();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListNusukPermits::class)
            ->assertOk()
            ->assertSee('Aishath Nazima')
            ->assertSee('Umrah permit');
    }

    public function test_visa_staff_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF))
            ->get(NusukPermitResource::getUrl('index'))
            ->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))
            ->get(NusukPermitResource::getUrl('index'))
            ->assertForbidden();
    }

    /** Read-only is a real state: chase it, answer the customer, move nothing. */
    public function test_pilgrim_support_can_look_but_not_move(): void
    {
        $permit = $this->permit();

        Livewire::actingAs($this->staff(Access::PILGRIM_SUPPORT))
            ->test(ListNusukPermits::class)
            ->assertOk()
            ->assertTableActionHidden('advance', $permit);
    }

    // ── Moving a permit ───────────────────────────────────────────────────

    public function test_a_permit_can_be_requested_from_the_screen(): void
    {
        $permit = $this->permit();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListNusukPermits::class)
            ->callTableAction('advance', $permit, [
                'to' => NusukPermit::REQUESTED,
                'reference' => 'NSK-1234',
            ]);

        $permit->refresh();

        $this->assertSame(NusukPermit::REQUESTED, $permit->status);
        $this->assertSame('NSK-1234', $permit->reference);
        $this->assertNotNull($permit->requested_at);
    }

    /**
     * The gate, through the screen.
     *
     * The service throws; what matters here is that the person pressing the
     * button is told why, and that the permit does not move. A silent
     * failure would leave it at "not started" with nobody able to say what
     * is wrong.
     */
    public function test_requesting_without_the_prerequisites_says_so_and_moves_nothing(): void
    {
        $permit = $this->permit($this->booking($this->departure(prerequisitesMet: false)));

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListNusukPermits::class)
            ->callTableAction('advance', $permit, ['to' => NusukPermit::REQUESTED])
            ->assertNotified();

        $this->assertSame(NusukPermit::NOT_STARTED, $permit->fresh()->status);
    }

    public function test_a_refusal_needs_a_stated_reason(): void
    {
        $permit = $this->permit();
        $permit->transitionTo(NusukPermit::REQUESTED);

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListNusukPermits::class)
            ->callTableAction('advance', $permit, ['to' => NusukPermit::REFUSED, 'reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(NusukPermit::REQUESTED, $permit->fresh()->status);
    }

    public function test_asking_again_after_a_refusal_opens_a_second_attempt(): void
    {
        $permit = $this->permit();
        $permit->transitionTo(NusukPermit::REQUESTED);
        $permit->transitionTo(NusukPermit::REFUSED, 'No accommodation on file at their end');

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListNusukPermits::class)
            // A refused permit is not on the default "Open" tab, and an
            // action can only be called on a row the page is actually
            // drawing.
            ->set('activeTab', 'refused')
            ->callTableAction('rerequest', $permit);

        $this->assertSame(2, NusukPermit::count());
        $this->assertSame(2, NusukPermit::orderByDesc('attempt')->first()->attempt);
        // The refusal keeps its reason: it is the evidence of what came back.
        $this->assertSame('No accommodation on file at their end', $permit->fresh()->refusal_reason);
    }

    // ── From the booking ──────────────────────────────────────────────────

    public function test_the_booking_opens_umrah_permits_and_only_those(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->callAction('openPermits');

        $this->assertSame(1, NusukPermit::count());
        $this->assertSame(NusukPermit::UMRAH, NusukPermit::sole()->kind);
    }

    /** Pressing it twice must not put two requests into a Saudi system. */
    public function test_opening_permits_twice_opens_one(): void
    {
        $booking = $this->booking();

        foreach ([1, 2] as $_) {
            Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
                ->test(EditBooking::class, ['record' => $booking->getKey()])
                ->callAction('openPermits');
        }

        $this->assertSame(1, NusukPermit::count());
    }

    public function test_booking_staff_are_not_offered_the_button(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertActionHidden('openPermits');
    }

    // ── Readiness, read back ──────────────────────────────────────────────

    /**
     * The state the whole design exists to express, on the screen somebody
     * actually opens.
     */
    public function test_the_booking_screen_names_what_a_traveller_still_needs(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(EditBooking::class, ['record' => $booking->getKey()])
            ->assertOk()
            ->assertSee('Aishath Nazima — still needs passport, visa, Umrah permit');
    }

    // ── The prerequisite gate is set on the departure ─────────────────────

    /**
     * Through `update()`, which is guarded — factories are not.
     *
     * Both columns were neither fillable nor cast when this was written, and
     * every test still passed, because `Factory::make()` runs unguarded. The
     * admin form does not: it would have dropped both values silently, and
     * the only symptom would have been permits that could never be requested
     * for a departure whose form said they could.
     */
    public function test_the_nusuk_prerequisites_can_actually_be_written(): void
    {
        $departure = $this->departure(prerequisitesMet: false);

        $departure->update([
            'nusuk_accommodation_recorded_at' => now(),
            'nusuk_transport_recorded_at' => now(),
        ]);

        $departure->refresh();

        $this->assertNotNull($departure->nusuk_accommodation_recorded_at);
        $this->assertNotNull($departure->nusuk_transport_recorded_at);
        $this->assertInstanceOf(Carbon::class, $departure->nusuk_accommodation_recorded_at);
        $this->assertSame([], NusukPermit::missingPrerequisites($departure));
    }

    /**
     * The readiness modal's contents, actually rendered.
     *
     * The view is rendered directly rather than through the action, because
     * in Filament a relation manager's modal is drawn by the *page* that
     * hosts it — mounting the action on the relation-manager component
     * produces HTML with no modal in it at all, so a test written that way
     * would assert against a page that never contained the thing it claims
     * to check. The wiring is covered by the action being on the row; what
     * is covered here is that the view compiles and says the right things.
     */
    public function test_the_readiness_view_names_who_cannot_travel(): void
    {
        $departure = $this->departure(prerequisitesMet: false);
        $booking = $this->booking($departure);
        $booking->forceFill(['status' => Booking::CONFIRMED])->save();

        $this->view('filament.departure-readiness', [
            'blockers' => TravelReadiness::departureBlockers($departure->fresh()),
            'missingPrerequisites' => NusukPermit::missingPrerequisites($departure),
            'labels' => [
                TravelReadiness::PASSPORT => 'a usable passport',
                TravelReadiness::VISA => 'a visa',
                TravelReadiness::PERMIT => 'an Umrah permit',
            ],
        ])
            ->assertSee('Aishath Nazima')
            ->assertSee('a usable passport')
            ->assertSee('an Umrah permit')
            // The gate on the departure itself, said in the same place.
            ->assertSee('so no permit can be');
    }

    /** Draft bookings are not people who are going. */
    public function test_the_readiness_view_ignores_unconfirmed_bookings(): void
    {
        $departure = $this->departure();
        $this->booking($departure);

        $this->view('filament.departure-readiness', [
            'blockers' => TravelReadiness::departureBlockers($departure->fresh()),
            'missingPrerequisites' => [],
            'labels' => [],
        ])
            ->assertSee('Everybody confirmed on this departure')
            ->assertDontSee('Aishath Nazima');
    }

    /** The action has to be on the row, or the view above is unreachable. */
    public function test_the_readiness_action_is_offered_on_every_departure(): void
    {
        $departure = $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $departure->package,
                'pageClass' => EditPackage::class,
            ])
            ->assertOk()
            ->assertTableActionVisible('readiness', $departure);
    }

    /**
     * The gate, readable from the departure list.
     *
     * Asserted on the relation-manager component, not on the package page:
     * Filament loads a relation manager lazily, in its own Livewire
     * request, so the page's own HTML contains no departures table at all.
     * A test that fetched the page and checked 200 would prove only that
     * the page loaded — this was written that way first, and rendering the
     * real page in a browser is what showed the table was not on it.
     */
    public function test_the_departure_list_says_when_nusuk_has_nothing_recorded(): void
    {
        $departure = $this->departure(prerequisitesMet: false);

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $departure->package,
                'pageClass' => EditPackage::class,
            ])
            ->assertOk()
            ->assertSee('no accommodation or transport');
    }

    public function test_the_departure_list_says_when_nusuk_has_both(): void
    {
        $departure = $this->departure();

        Livewire::actingAs($this->staff(Access::OPERATIONS_MANAGER))
            ->test(DeparturesRelationManager::class, [
                'ownerRecord' => $departure->package,
                'pageClass' => EditPackage::class,
            ])
            ->assertOk()
            ->assertSee('recorded')
            ->assertDontSee('no accommodation');
    }

    /** The package screen itself still has to load. */
    public function test_the_package_screen_loads(): void
    {
        $departure = $this->departure();

        $this->actingAs($this->staff(Access::OPERATIONS_MANAGER))
            // The model, not its id: Package is routed by slug.
            ->get(PackageResource::getUrl('edit', ['record' => $departure->package]))
            ->assertOk();
    }
}
