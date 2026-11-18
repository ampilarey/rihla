<?php

namespace Tests\Feature;

use App\Filament\Company\Pages\CompanySettings;
use App\Filament\Company\Pages\Team;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Document;
use App\Models\Package;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Booking\BookingDesk;
use App\Services\Companies\AddCompany;
use App\Services\Documents\DocumentWallet;
use App\Support\Access;
use App\Support\CompanyContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2's "done when" (docs/UMRAH_COMPANIES_PLAN.md): a test company runs
 * a trip from registration to return without touching `/staff`.
 *
 * Rihla's admin adds the company; from then on every step is the company's
 * own people, signed in to the company's own panel, each in their role:
 * the owner sets the company up and builds the team, a package is made,
 * booking staff take a booking at the desk, a passport is filed, Finance
 * checks the money in, the operations manager moves the departure through
 * its stages to its return, and the invoice prints with the company's own
 * name. At the end, every row is the company's and Rihla's own screens
 * have seen none of it.
 */
class CompanyRunsATripTest extends TestCase
{
    use RefreshDatabase;

    private Partner $company;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('documents');
    }

    /** Signed in to the company's panel as one of its people. */
    private function as(User $user): void
    {
        $this->app->forgetScopedInstances();
        $this->actingAs($user);
        Filament::setCurrentPanel('company');
        Filament::setTenant($this->company);
        app(CompanyContext::class)->use($this->company->id);
    }

    private function person(string $email): User
    {
        return User::where('email', $email)->sole();
    }

    public function test_a_company_runs_a_trip_from_registration_to_return_without_staff(): void
    {
        // Rihla's admin, once, after checking the licence.
        $this->company = app(AddCompany::class)('Coral Umrah', 'Aishath Coral', 'owner@coral.example');
        $owner = $this->person('owner@coral.example');

        // The owner sets the company up and builds the team.
        $this->as($owner);
        Livewire::test(CompanySettings::class)
            ->fillForm(['name' => 'Coral Umrah Pvt Ltd', 'phone' => '+960 333 4444', 'reference_prefix' => 'CRL', 'bank_name' => 'BML', 'bank_account_name' => 'Coral Umrah', 'bank_accounts' => ['MVR' => '7701-0001']])
            ->call('save')
            ->assertHasNoFormErrors();

        foreach ([
            ['Hassan', 'desk@coral.example', Access::BOOKING_STAFF],
            ['Mariyam', 'money@coral.example', Access::FINANCE],
            ['Ibrahim', 'ops@coral.example', Access::OPERATIONS_MANAGER],
        ] as [$name, $email, $role]) {
            Livewire::test(Team::class)->callAction('add', ['name' => $name, 'email' => $email, 'role' => $role])->assertHasNoActionErrors();
        }

        // A package, through the company's own form, and its departure.
        Livewire::test(CreatePackage::class)
            ->fillForm(['slug' => 'ramadan-umrah', 'title' => ['en' => 'Ramadan Umrah'], 'summary' => ['en' => 'Fourteen nights.']])
            ->call('create')
            ->assertHasNoFormErrors();
        $package = Package::sole();
        $departure = Departure::factory()->withSeats(20)->create([
            'package_id' => $package->id,
            'date_start' => now()->addDays(60)->toDateString(),
            'date_end' => now()->addDays(74)->toDateString(),
        ]);
        $departure->priceTiers()->create(['occupancy' => 'quad', 'pax_type' => 'adult', 'amount_minor' => 2_850_000]);

        // Booking staff take a booking at the desk.
        $this->as($this->person('desk@coral.example'));
        $this->assertTrue(auth()->user()->can('booking.create'));
        $customer = Customer::factory()->create(['name' => 'Aminath Ali']);
        $pilgrim = Traveller::factory()->for($customer)->create(['full_name' => 'Aminath Ali']);
        $booking = app(BookingDesk::class)->open($departure, $customer, [['traveller_id' => $pilgrim->id, 'occupancy' => 'quad']]);
        $this->assertStringStartsWith('CRL-B-', $booking->reference, 'The company\'s own letters.');

        // Their passport is filed.
        app(DocumentWallet::class)->store($pilgrim, UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 scan'), 'travel', 'passport');

        // The pilgrim pays in full; Finance checks it in from the payments screen.
        $payment = Payment::factory()->awaitingReview()->create([
            'payable_type' => Booking::class,
            'payable_id' => $booking->id,
            'amount_minor' => $booking->total_minor,
        ]);
        $this->as($this->person('money@coral.example'));
        Livewire::test(ListPayments::class)->callTableAction('reconcile', $payment, ['note' => 'On the BML statement']);
        $this->assertSame(Payment::SUCCEEDED, $payment->fresh()?->status);
        $this->assertSame($booking->total_minor, $booking->fresh()?->paid_minor);

        // The invoice prints, for the company, with its own name on it.
        $this->get(route('staff.invoice', ['booking' => $booking->id, 'company' => $this->company->slug]))->assertOk();

        // Operations moves the departure through its stages to its return.
        $this->as($this->person('ops@coral.example'));
        foreach ([Departure::CLOSED, Departure::DEPARTED, Departure::RETURNED] as $stage) {
            $departure->fresh()?->transitionTo($stage, 'As planned', auth()->user());
        }
        $this->assertSame(Departure::RETURNED, $departure->fresh()?->status);

        // Every row of the trip is the company's.
        $context = app(CompanyContext::class);
        foreach ([Package::class, Departure::class, Customer::class, Traveller::class, Booking::class, Payment::class, Document::class] as $model) {
            $rows = $context->acrossEveryCompany(fn () => $model::query()->pluck('company_id')->unique()->values()->all());
            $this->assertSame([$this->company->id], $rows, "{$model} rows are not all the company's.");
        }

        // And Rihla's own screens saw none of it.
        $context->as((int) Partner::rihla()?->id, function (): void {
            $this->assertSame(0, Booking::count());
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, Customer::count());
        });

        // Nobody in the company holds any part of Rihla's panel.
        foreach (['owner', 'desk', 'money', 'ops'] as $who) {
            $this->assertFalse($this->person("{$who}@coral.example")->hasAnyRole(Access::ROLES), "{$who} holds a Rihla role.");
        }
    }
}
