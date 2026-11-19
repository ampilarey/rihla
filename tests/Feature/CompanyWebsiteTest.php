<?php

namespace Tests\Feature;

use App\Filament\Company\Pages\Website;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Models\CompanySite;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Package;
use App\Models\Partner;
use App\Models\User;
use App\Services\Companies\AddCompany;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\Contact;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A company's own website (companies plan, Phase 4a): its address serves
 * its own pages in its own frame, nothing of Rihla's and nothing of any
 * other company's, and only once Rihla's admin has switched it on and the
 * company has published it.
 */
class CompanyWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'rihla.test';

    private Partner $coral;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['companies.site_domain' => self::DOMAIN]);

        $this->coral = $this->company('Coral Umrah', 'coral@example.com');
    }

    private function company(string $name, string $email, bool $live = true): Partner
    {
        $company = app(AddCompany::class)($name, 'Owner of '.$name, $email);
        $company->forceFill(['site_enabled' => $live, 'phone' => '7771234', 'registration_number' => 'C-0420/2026'])->save();

        $site = new CompanySite(['tagline' => ['en' => 'Umrah with '.$name], 'colour_accent' => '#14532D']);
        $site->company_id = (int) $company->getKey();
        $site->published_at = $live ? now() : null;
        $site->save();

        return $company->fresh();
    }

    private function at(Partner $company, string $path = '/en'): string
    {
        return 'http://'.$company->slug.'.'.self::DOMAIN.$path;
    }

    /** A published package with an open departure, made as the given company (null: Rihla). */
    private function sellable(?Partner $company, string $title, string $slug): Package
    {
        $make = function () use ($title, $slug): Package {
            $package = Package::factory()->create(['title' => ['en' => $title], 'slug' => $slug]);
            Departure::factory()->withSeats(20)->create(['package_id' => $package->id, 'date_start' => now()->addDays(40), 'date_end' => now()->addDays(54)]);

            return $package;
        };

        return $company === null ? $make() : app(CompanyContext::class)->as($company->id, $make);
    }

    public function test_the_address_serves_the_companys_home_page_and_nothing_of_rihlas(): void
    {
        $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $this->sellable(null, 'Rihla Signature Umrah', 'rihla-signature');
        $this->get('/en')->assertOk()->assertSee('Rihla Signature Umrah');

        $this->get($this->at($this->coral))
            ->assertOk()
            ->assertSee('Coral Umrah')
            ->assertSee('Umrah with Coral Umrah')
            ->assertSee('Coral Ramadan Umrah')
            ->assertDontSee('Rihla Signature Umrah')
            ->assertDontSee('Rihla Travels')
            ->assertDontSee('manifest.json', false)
            ->assertDontSee('sw.js', false)
            ->assertDontSee('Powered by Rihla');

        // Rihla's own home page is untouched, and shows nothing of Coral's.
        // Absolute: the test client would otherwise send a relative path to
        // the host of the request before it.
        $this->get(config('app.url').'/en')->assertOk()->assertSee('Rihla Signature Umrah')->assertDontSee('Coral Ramadan Umrah');
    }

    public function test_only_the_sites_own_pages_answer_on_its_address(): void
    {
        foreach (['/en/articles', '/en/guide', '/en/trips', '/en/stays', '/en/gallery', '/en/people', '/en/portal', '/staff', '/company/'.$this->coral->slug, '/login', '/api/health', '/pulse', '/sitemap.xml'] as $path) {
            $this->get($this->at($this->coral, $path))->assertNotFound();
        }

        $this->get($this->at($this->coral, '/'))->assertRedirect();
        $this->get($this->at($this->coral, '/en/contact'))->assertOk()->assertSee('Contact')->assertSee('7771234');
        $this->get($this->at($this->coral, '/en/packages'))->assertOk();
    }

    public function test_a_package_page_is_the_companys_own_and_names_the_company(): void
    {
        $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $this->sellable(null, 'Rihla Signature Umrah', 'rihla-signature');
        $amber = $this->company('Amber Hajj', 'amber@example.com');
        $this->sellable($amber, 'Amber Shawwal Umrah', 'amber-shawwal');

        $this->get($this->at($this->coral, '/en/packages'))->assertOk()
            ->assertSee('Coral Ramadan Umrah')
            ->assertDontSee('Rihla Signature Umrah')
            ->assertDontSee('Amber Shawwal Umrah');

        $page = $this->get($this->at($this->coral, '/en/packages/coral-ramadan'))->assertOk();
        // Online booking is not open on a company's site yet: the visitor asks.
        $page->assertSee('Ask about this trip')->assertDontSee('/book', false);
        // Structured data names the company, never Rihla.
        $page->assertSee('"name":"Coral Umrah"', false)->assertSee('C-0420/2026');

        $this->get($this->at($this->coral, '/en/packages/rihla-signature'))->assertNotFound();
        $this->get($this->at($this->coral, '/en/packages/amber-shawwal'))->assertNotFound();
    }

    public function test_a_site_answers_only_once_switched_on_and_published(): void
    {
        $dark = $this->company('Dark Umrah', 'dark@example.com', live: false);
        $this->get($this->at($dark))->assertNotFound();

        $dark->forceFill(['site_enabled' => true])->save();
        $this->get($this->at($dark))->assertNotFound();

        $dark->site->forceFill(['published_at' => now()])->save();
        $this->get($this->at($dark))->assertOk()->assertSee('Dark Umrah');

        // Rihla's admin switching it off takes it down at once.
        $dark->forceFill(['site_enabled' => false])->save();
        $this->get($this->at($dark))->assertNotFound();

        // A name that is no company says nothing, and is never a copy of rihla.mv.
        $this->get('http://nobody.'.self::DOMAIN.'/en')->assertNotFound();
    }

    public function test_reserved_and_deeper_names_are_never_a_company(): void
    {
        $this->assertNull(CurrentSite::label('www.'.self::DOMAIN));
        $this->assertNull(CurrentSite::label('test.'.self::DOMAIN));
        $this->assertNull(CurrentSite::label('a.coral.'.self::DOMAIN));
        $this->assertNull(CurrentSite::label(self::DOMAIN));
        $this->assertSame('coral', CurrentSite::label('Coral.'.self::DOMAIN));

        config(['companies.site_domain' => null]);
        $this->assertNull(CurrentSite::label('coral.'.self::DOMAIN));
    }

    public function test_an_enquiry_on_the_site_lands_with_the_company(): void
    {
        $coralPackage = $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $rihlaPackage = $this->sellable(null, 'Rihla Signature Umrah', 'rihla-signature');

        $this->get($this->at($this->coral, '/en/contact'))->assertOk()->assertSee('Coral Ramadan Umrah')->assertDontSee('Rihla Signature Umrah');

        // Rihla's package is refused in words, not with an error page.
        $this->post($this->at($this->coral, '/en/contact'), ['name' => 'Aminath', 'phone' => '7779999', 'package_id' => $rihlaPackage->id])
            ->assertSessionHasErrors('package_id');

        $this->post($this->at($this->coral, '/en/contact'), ['name' => 'Aminath', 'phone' => '7779999', 'package_id' => $coralPackage->id])
            ->assertRedirect($this->at($this->coral, '/en/contact'));

        $enquiry = app(CompanyContext::class)->acrossEveryCompany(fn () => Enquiry::query()->sole());
        $this->assertSame($this->coral->id, $enquiry->company_id);
    }

    public function test_the_companys_numbers_never_fall_back_to_rihlas(): void
    {
        app(CompanyContext::class)->as($this->coral->id, function (): void {
            $this->assertSame('9607771234', Contact::whatsappNumber());

            $this->coral->forceFill(['phone' => null])->save();
            $this->assertSame('', Contact::whatsappNumber(), 'A company with no number gets none, not Rihla\'s.');
        });

        $this->assertSame(Contact::FALLBACK, Contact::whatsappNumber());
    }

    public function test_an_error_on_the_site_is_the_companys(): void
    {
        $this->get($this->at($this->coral, '/en/no-such-page'))
            ->assertNotFound()
            ->assertSee('Coral Umrah')
            ->assertDontSee('Rihla Travels');
    }

    public function test_powered_by_rihla_shows_only_when_switched_on(): void
    {
        $this->coral->forceFill(['powered_by_rihla' => true])->save();

        $this->get($this->at($this->coral))->assertOk()->assertSee('Powered by Rihla');
    }

    public function test_rihlas_admin_switches_a_site_on_from_staff(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $this->coral->id])
            ->fillForm(['site_enabled' => false, 'powered_by_rihla' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $coral = $this->coral->fresh();
        $this->assertFalse($coral->site_enabled);
        $this->assertTrue($coral->powered_by_rihla);
    }

    public function test_the_owner_builds_and_publishes_the_site_with_readable_colours(): void
    {
        $fresh = app(AddCompany::class)('Pearl Umrah', 'Pearl Owner', 'pearl@example.com');
        $owner = User::where('email', 'pearl@example.com')->sole();

        $this->actingAs($owner)->get('/company/'.$fresh->slug)->assertOk();
        Filament::setTenant($fresh);

        // A pale headings colour that does not read on a white page is refused.
        Livewire::test(Website::class)
            ->fillForm(['colour_primary' => '#BBBBBB', 'tagline' => ['en' => 'Pearl'], 'theme' => CompanySite::CLASSIC, 'font' => 'inter'])
            ->call('save')
            ->assertHasErrors();

        Livewire::test(Website::class)
            ->fillForm([
                'colour_accent' => '#1E3A8A',
                'colour_primary' => '#1E3A8A',
                'tagline' => ['en' => 'Umrah from Pearl', 'dv' => ''],
                'theme' => CompanySite::CLASSIC,
                'font' => 'inter',
                'sections' => ['departures', 'about'],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->callAction('publish');

        $site = $fresh->fresh()->site;
        $this->assertSame('Umrah from Pearl', $site->getTranslation('tagline', 'en'));
        $this->assertFalse($site->hasTranslation('tagline', 'dv'));
        $this->assertTrue($site->isPublished());
        $this->assertFalse($site->shows('contact'));

        // Published, but not live until Rihla switches it on.
        $this->get($this->at($fresh))->assertNotFound();
    }
}
