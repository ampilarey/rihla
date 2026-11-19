<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Partner;
use App\Models\User;
use App\Services\Companies\AddCompany;
use App\Services\Companies\CompanyTeam;
use App\Services\Documents\Reading\ReaderManager;
use App\Services\Documents\Reading\Readers\ManualReader;
use App\Services\Telegram\Bot;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CompanyFeatures;
use App\Support\CompanyRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a company has paid for (companies plan, Phase 5a): one switch per
 * feature, on, read-only or off, read by the company gate, the company's
 * site, the bot, the reader and the team screen. Rihla has everything.
 */
class CompanyFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'rihla.test';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['companies.site_domain' => self::DOMAIN]);
    }

    /** @param  array<string, string>  $features */
    private function company(array $features): Partner
    {
        $company = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com', null, $features);
        $company->forceFill(['site_enabled' => true, 'registration_paid_at' => now()])->save();

        $site = $company->site()->firstOrNew();
        $site->company_id = (int) $company->getKey();
        $site->published_at = now();
        $site->save();

        app(CompanyContext::class)->as((int) $company->getKey(), function (): void {
            $package = Package::factory()->create(['slug' => 'coral-ramadan']);
            Departure::factory()->withSeats(10)->create(['package_id' => $package->id, 'date_start' => now()->addDays(30), 'date_end' => now()->addDays(44)]);
        });

        return $company->fresh();
    }

    private function owner(): User
    {
        return User::where('email', 'coral@example.com')->sole();
    }

    private function at(Partner $company, string $path): string
    {
        return 'http://'.$company->slug.'.'.self::DOMAIN.$path;
    }

    public function test_a_feature_that_is_off_is_absent_from_the_panel_and_read_only_only_shows(): void
    {
        $company = $this->company([]);
        $base = '/company/'.$company->slug;

        $this->actingAs($this->owner())->get($base.'/bookings')->assertForbidden();
        // Packages and departures come with the basic website.
        $this->actingAs($this->owner())->get($base.'/packages')->assertOk();

        $company->forceFill(['features' => [CompanyFeatures::BOOKINGS => CompanyFeatures::READ_ONLY]])->save();
        $this->actingAs($this->owner())->get($base.'/bookings')->assertOk();
        $this->actingAs($this->owner())->get($base.'/bookings/create')->assertForbidden();

        $company->forceFill(['features' => [CompanyFeatures::BOOKINGS => CompanyFeatures::ON]])->save();
        $this->actingAs($this->owner())->get($base.'/bookings/create')->assertOk();
    }

    public function test_rihla_has_everything_whatever_is_stored(): void
    {
        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);

        foreach (array_keys(CompanyFeatures::FEATURES) as $feature) {
            $this->assertTrue(CompanyFeatures::has($rihla, $feature));
        }

        $this->assertTrue(CompanyFeatures::allows($rihla, 'booking.create'));
    }

    public function test_the_site_offers_only_what_the_company_has(): void
    {
        $company = $this->company([]);

        // The basic website: trips and contact, but no checkout, portal or app.
        $this->get($this->at($company, '/en/packages/coral-ramadan'))->assertOk()->assertSee('Ask about this trip')->assertDontSee('/book', false);
        $this->get($this->at($company, '/en/packages/coral-ramadan/book'))->assertNotFound();
        $this->get($this->at($company, '/en/portal/login'))->assertNotFound();
        $this->get($this->at($company, '/manifest.webmanifest'))->assertNotFound();
        $this->get($this->at($company, '/en'))->assertOk()->assertDontSee('manifest.webmanifest', false);

        $company->forceFill(['features' => [
            CompanyFeatures::BOOKINGS => CompanyFeatures::ON,
            CompanyFeatures::PORTAL => CompanyFeatures::READ_ONLY,
            CompanyFeatures::APP => CompanyFeatures::ON,
        ]])->save();

        $this->get($this->at($company, '/en/packages/coral-ramadan/book'))->assertOk();
        // A downgraded portal still lets a customer see their booking.
        $this->get($this->at($company, '/en/portal/login'))->assertOk();
        $this->get($this->at($company, '/manifest.webmanifest'))->assertOk();
    }

    public function test_an_own_domain_answers_only_while_it_is_paid_for(): void
    {
        $company = $this->company([]);
        $company->forceFill(['site_domain' => 'coralumrah.test'])->save();

        // Not paid for: a 404 — never Rihla's site on an address that reads
        // as the company's (security review).
        $this->get('http://coralumrah.test/en')->assertNotFound();

        $company->forceFill(['features' => [CompanyFeatures::DOMAIN => CompanyFeatures::ON]])->save();
        $this->get('http://coralumrah.test/en')->assertOk()->assertSee('Coral Umrah');
    }

    public function test_the_bot_and_the_reader_follow_their_switches(): void
    {
        config(['documents.reader' => 'tesseract']);
        $company = $this->company([]);
        $company->forceFill(['telegram_bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghij'])->save();

        app(CompanyContext::class)->as($company->id, function (): void {
            $this->assertNull(Bot::token(), 'A bot the company has not paid for is not configured.');
            $this->assertInstanceOf(ManualReader::class, app(ReaderManager::class)->reader());
        });

        $company->forceFill(['features' => [CompanyFeatures::TELEGRAM => CompanyFeatures::ON]])->save();

        app(CompanyContext::class)->as($company->id, function (): void {
            $this->assertSame('123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghij', Bot::token());
        });
    }

    public function test_staff_seats_cap_the_team(): void
    {
        $company = $this->company([]);
        $company->forceFill(['staff_seats' => 2])->save();
        $team = app(CompanyTeam::class);

        $team->add($company, 'Mariyam', 'mariyam@example.com', CompanyRole::ROLES[2]);

        $this->expectException(DeskRefusal::class);
        $this->expectExceptionMessage('2 staff seats');
        $team->add($company->fresh(), 'Hassan', 'hassan@example.com', CompanyRole::ROLES[2]);
    }

    public function test_rihlas_admin_sets_what_was_paid_for_and_the_fee_opens_the_site(): void
    {
        $company = $this->company([]);
        $company->forceFill(['site_enabled' => false, 'registration_paid_at' => null])->save();
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        // No fee recorded: the site cannot be switched on.
        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $company->id])
            ->fillForm(['site_enabled' => true])
            ->call('save')
            ->assertHasErrors(['data.site_enabled']);
        $this->assertFalse($company->fresh()->site_enabled);

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $company->id])
            ->fillForm([
                'site_enabled' => true,
                'registration_fee' => '1500',
                'registration_fee_currency' => 'MVR',
                'registration_paid_at' => now()->toDateString(),
                'staff_seats' => 5,
                'features.bookings' => CompanyFeatures::ON,
                'features.books' => CompanyFeatures::READ_ONLY,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company = $company->fresh();
        $this->assertTrue($company->site_enabled);
        $this->assertSame(150000, $company->registration_fee_minor);
        $this->assertSame(5, $company->staff_seats);
        // assertEquals: MySQL keeps JSON keys in an order of its own.
        $this->assertEquals([CompanyFeatures::BOOKINGS => CompanyFeatures::ON, CompanyFeatures::BOOKS => CompanyFeatures::READ_ONLY], $company->features);

        // And the company sees it, read-only, on its own details page.
        $this->actingAs($this->owner())->get('/company/'.$company->slug.'/details')
            ->assertOk()
            ->assertSee('Your plan')
            ->assertSee('1 of 5 staff seats used');
    }

    public function test_a_company_already_working_here_keeps_everything_when_features_arrive(): void
    {
        $company = $this->company([]);
        DB::table('partners')->where('id', $company->id)->update(['features' => null]);
        $chosen = $this->company([]);
        DB::table('partners')->where('id', $chosen->id)->update(['features' => json_encode(['books' => 'on'])]);

        (require database_path('migrations/2026_11_20_130100_keep_what_existing_companies_use.php'))->up();

        $this->assertTrue(CompanyFeatures::has($company->fresh(), CompanyFeatures::BOOKINGS));
        $this->assertSame(['books' => 'on'], $chosen->fresh()->features, 'A company whose features were already set is left alone.');
    }
}
