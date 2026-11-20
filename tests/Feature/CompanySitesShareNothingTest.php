<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\SiteAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Companies\Services\AddCompany;
use Modules\Stays\Models\HostPage;
use Modules\Umrah\Models\Booking;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Package;
use Modules\Umrah\Services\Portal\Gatekeeper;
use Tests\TestCase;

/**
 * What marks Phase 4 of docs/UMRAH_COMPANIES_PLAN.md done: two companies
 * have sites that share nothing visible with each other or with rihla.mv —
 * not a package, a page, a sitemap entry, a portal link or a name.
 */
class CompanySitesShareNothingTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'rihla.test';

    private Partner $coral;

    private Partner $amber;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['companies.site_domain' => self::DOMAIN]);

        $this->coral = $this->company('Coral Umrah', 'coral@example.com', 'coral-ramadan', 'Coral Ramadan Umrah');
        $this->amber = $this->company('Amber Hajj', 'amber@example.com', 'amber-shawwal', 'Amber Shawwal Umrah');
        $this->sellable('rihla-signature', 'Rihla Signature Umrah');
    }

    private function company(string $name, string $email, string $slug, string $title): Partner
    {
        $company = app(AddCompany::class)($name, 'Owner of '.$name, $email);
        $company->forceFill(['site_enabled' => true])->save();

        $site = $company->site()->firstOrNew();
        $site->fill(['tagline' => ['en' => $name.' tagline']]);
        $site->company_id = (int) $company->getKey();
        $site->published_at = now();
        $site->save();

        app(CompanyContext::class)->as((int) $company->getKey(), fn () => $this->sellable($slug, $title));

        return $company->fresh();
    }

    private function sellable(string $slug, string $title): Package
    {
        $package = Package::factory()->create(['title' => ['en' => $title], 'slug' => $slug]);
        Departure::factory()->withSeats(20)->create(['package_id' => $package->id, 'date_start' => now()->addDays(40), 'date_end' => now()->addDays(54)]);

        return $package;
    }

    private function at(Partner $company, string $path = '/en'): string
    {
        return 'http://'.$company->slug.'.'.self::DOMAIN.$path;
    }

    private function rihla(string $path): string
    {
        return config('app.url').$path;
    }

    public function test_each_site_shows_its_own_and_nothing_of_the_other_or_of_rihla(): void
    {
        $cases = [
            [$this->coral, 'Coral Ramadan Umrah', 'coral-ramadan', ['Amber', 'amber-shawwal', 'Rihla', 'rihla-signature']],
            [$this->amber, 'Amber Shawwal Umrah', 'amber-shawwal', ['Coral', 'coral-ramadan', 'Rihla', 'rihla-signature']],
        ];

        foreach ($cases as [$company, $title, $slug, $foreign]) {
            foreach (['/en', '/en/packages', '/en/contact', '/sitemap.xml', '/manifest.webmanifest', '/robots.txt', '/offline'] as $path) {
                $page = $this->get($this->at($company, $path))->assertOk();

                foreach ($foreign as $word) {
                    $page->assertDontSee($word, false);
                }
            }

            $this->get($this->at($company, '/en/packages'))->assertSee($title);
            $this->get($this->at($company, '/en/packages/'.$slug))->assertOk();
        }

        // And rihla.mv shows neither company.
        foreach (['/en', '/en/packages', '/sitemap.xml', '/en/contact'] as $path) {
            $this->get($this->rihla($path))->assertOk()
                ->assertDontSee('Coral')->assertDontSee('coral-ramadan')
                ->assertDontSee('Amber')->assertDontSee('amber-shawwal');
        }
    }

    public function test_one_companys_portal_link_opens_nothing_on_the_others_site(): void
    {
        [$booking, $token] = app(CompanyContext::class)->as($this->coral->id, function (): array {
            $booking = Booking::factory()->create([
                'customer_id' => Customer::factory()->create()->id,
                'departure_id' => Package::where('slug', 'coral-ramadan')->sole()->departures()->sole()->id,
                'seats' => 1,
            ]);

            return [$booking, app(Gatekeeper::class)->issue($booking)];
        });

        $this->get($this->at($this->amber, '/en/portal/enter/'.$token));
        $this->get($this->at($this->amber, '/en/portal'))->assertRedirect($this->at($this->amber, '/en/portal/locked'));

        $this->get($this->at($this->coral, '/en/portal/enter/'.$token));
        $this->get($this->at($this->coral, '/en/portal'))->assertOk()->assertSee($booking->reference);
    }

    public function test_a_company_on_its_own_domain(): void
    {
        $this->coral->forceFill(['site_domain' => 'coralumrah.test'])->save();

        $this->get('http://coralumrah.test/en')->assertOk()->assertSee('Coral Umrah')->assertDontSee('Rihla');
        $this->get('http://www.coralumrah.test/en/packages')->assertOk()->assertSee('Coral Ramadan Umrah');
        $this->get('http://coralumrah.test/staff')->assertNotFound();

        // Its customers' links use the domain it chose.
        $this->assertStringStartsWith('http://coralumrah.test/en/portal/enter/', (string) SiteAddress::route('portal.enter', ['locale' => 'en', 'token' => 'abc'], $this->coral->id));

        // A domain nobody has mapped is not anybody's site.
        $this->get('http://someone-else.test/en')->assertOk()->assertDontSee('Coral');
    }

    public function test_an_umrah_company_is_never_a_guesthouse_host_on_rihla_mv(): void
    {
        $page = new HostPage(['tagline' => ['en' => 'Coral as a host']]);
        $page->partner_id = $this->coral->id;
        $page->published_at = now();
        $page->save();

        $this->get($this->rihla('/en/stays/hosts/'.$this->coral->slug))->assertNotFound();
        $this->get($this->rihla('/sitemap.xml'))->assertDontSee('/stays/hosts/'.$this->coral->slug);
    }

    public function test_a_companys_leader_pages_open_for_its_own_people_only(): void
    {
        $owner = User::where('email', 'coral@example.com')->sole();
        $stranger = User::where('email', 'amber@example.com')->sole();

        // Without the company it is Rihla's leader pages, where they are
        // nobody. First: in a test one application serves every request,
        // and the company a request names would outlive it.
        $this->actingAs($owner)->get($this->rihla('/en/leader'))->assertForbidden();
        $this->actingAs($owner)->get($this->rihla('/en/leader?company='.$this->coral->slug))->assertOk();
        // Another company's owner cannot name Coral.
        $this->actingAs($stranger)->get($this->rihla('/en/leader?company='.$this->coral->slug))->assertNotFound();
    }
}
