<?php

namespace Modules\Companies\Tests;

use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Filament\Resources\Partners\Pages\ListPartners;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyFeatures;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Companies\Filament\Pages\Website;
use Modules\Companies\Models\CompanySite;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Package;
use Modules\Umrah\Models\PriceTier;
use Tests\TestCase;

/**
 * What marks Phase 5 of docs/UMRAH_COMPANIES_PLAN.md done: the owner adds a
 * company in minutes, and it takes its first booking without help.
 *
 * Every step Rihla's admin takes is a screen in /staff; every step the
 * company takes is its own. Running a whole trip in the panel is
 * CompanyRunsATripTest's; this one is about getting a company to its first
 * booking.
 */
class CompanyOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_adds_a_company_and_it_takes_its_first_booking_without_help(): void
    {
        Notification::fake();
        config(['companies.site_domain' => 'rihla.test']);
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        // 1. Rihla's admin adds the company, licence checked, with bookings
        //    and the portal paid for.
        Livewire::actingAs($admin)->test(ListPartners::class)
            ->callAction('addCompany', data: [
                'name' => 'Coral Umrah',
                'owner_name' => 'Aishath Coral',
                'owner_email' => 'coral@example.com',
                'licence_number' => 'MIA-HU-0420',
                'licence_expires_on' => now()->addYear()->toDateString(),
                'licence_register' => 'Ministry of Islamic Affairs register of Hajj and Umrah operators',
                'registration_fee' => '1500',
                'staff_seats' => 3,
                'features' => [CompanyFeatures::BOOKINGS, CompanyFeatures::PORTAL],
            ])
            ->assertHasNoActionErrors();
        $coral = Partner::where('name', 'Coral Umrah')->sole();

        // 2. The fee arrives: the admin records it and switches the site on.
        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $coral->id])
            ->fillForm(['registration_paid_at' => now()->toDateString(), 'site_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($coral->fresh()->site_enabled);

        // 3. The company's owner — who chose their own password from the
        //    e-mail — fills in and publishes the website.
        $owner = User::where('email', 'coral@example.com')->sole();
        $this->actingAs($owner)->get('/company/'.$coral->slug)->assertOk();
        Filament::setTenant($coral->fresh());
        Livewire::test(Website::class)
            ->fillForm(['theme' => CompanySite::CLASSIC, 'font' => 'inter', 'tagline' => ['en' => 'Umrah with Coral'], 'colour_accent' => '#14532D', 'colour_primary' => '#14532D', 'sections' => ['departures', 'about', 'contact']])
            ->call('save')
            ->assertHasNoErrors()
            ->callAction('publish');

        // 4. …and puts a trip on sale. (Creating it through the panel's own
        //    screens is CompanyRunsATripTest's; here it is the company's.)
        app(CompanyContext::class)->as($coral->id, function (): void {
            $package = Package::factory()->create(['title' => ['en' => 'Coral Ramadan Umrah'], 'slug' => 'coral-ramadan']);
            $departure = Departure::factory()->withSeats(20)->create(['package_id' => $package->id, 'date_start' => now()->addDays(60), 'date_end' => now()->addDays(74)]);
            PriceTier::create(['departure_id' => $departure->id, 'occupancy' => 'quad', 'pax_type' => PriceTier::ADULT, 'amount_minor' => 2_850_000]);
        });

        // 5. A pilgrim finds it on the company's own address and books.
        $site = 'http://'.$coral->slug.'.rihla.test';
        $this->get($site.'/en')->assertOk()->assertSee('Umrah with Coral')->assertSee('Coral Ramadan Umrah');
        $departure = app(CompanyContext::class)->as($coral->id, fn () => Departure::query()->sole());

        $this->post($site.'/en/packages/coral-ramadan/book', ['departure' => $departure->id, 'occupancy' => 'quad', 'seats' => 1])
            ->assertRedirect($site.'/en/book/travellers');
        $this->post($site.'/en/book/travellers', [
            'contact_name' => 'Mariyam', 'contact_phone' => '7779999', 'contact_email' => 'mariyam@example.mv',
            'travellers' => [['full_name' => 'Mariyam Hassan', 'date_of_birth' => now()->subYears(40)->toDateString(), 'gender' => 'female']],
        ])->assertRedirect($site.'/en/book/review');
        $this->post($site.'/en/book/review', ['confirmed' => '1'])->assertRedirect($site.'/en/book/confirmation');

        // 6. The booking is the company's, and its panel shows it. Nobody at
        //    Rihla was involved after step 2.
        $booking = app(CompanyContext::class)->acrossEveryCompany(fn () => Booking::query()->sole());
        $this->assertSame($coral->id, $booking->company_id);

        $this->flushSession();
        $this->actingAs($owner->fresh())->get(config('app.url').'/company/'.$coral->slug.'/bookings')->assertOk()->assertSee($booking->reference);
    }
}
