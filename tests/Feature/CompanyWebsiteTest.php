<?php

namespace Tests\Feature;

use App\Filament\Company\Pages\Website;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Models\Booking;
use App\Models\CompanySite;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Package;
use App\Models\Partner;
use App\Models\PriceTier;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\Waitlist;
use App\Services\Companies\AddCompany;
use App\Services\Portal\Gatekeeper;
use App\Support\Access;
use App\Support\Brand;
use App\Support\CompanyContext;
use App\Support\Contact;
use App\Support\Contrast;
use App\Support\CurrentSite;
use App\Support\SiteAddress;
use App\Support\SitePalette;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
            ->assertDontSee("'/sw.js'", false)
            ->assertDontSee('/sw.js\'', false)
            ->assertDontSee('Powered by Rihla');

        // Rihla's own home page is untouched, and shows nothing of Coral's.
        // Absolute: the test client would otherwise send a relative path to
        // the host of the request before it.
        $this->get(config('app.url').'/en')->assertOk()->assertSee('Rihla Signature Umrah')->assertDontSee('Coral Ramadan Umrah');
    }

    public function test_only_the_sites_own_pages_answer_on_its_address(): void
    {
        foreach (['/en/articles', '/en/guide', '/en/trips', '/en/stays', '/en/gallery', '/en/people', '/en/learning', '/staff', '/company/'.$this->coral->slug, '/login', '/api/health', '/pulse'] as $path) {
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
        // Booking is open on the company's site (Phase 4c), at its own address.
        $page->assertSee($this->at($this->coral, '/en/packages/coral-ramadan/book'), false)->assertDontSee('Ask about this trip');
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

    // ── Phase 4b: themes, colours, sitemap ───────────────────────────────

    public function test_the_inner_pages_wear_the_companys_colours_and_stay_readable(): void
    {
        $site = $this->coral->site;
        $site->forceFill(['colour_primary' => '#1E3A8A', 'colour_accent' => '#FACC15'])->save();
        $css = (new SitePalette($site->fresh()))->css();

        // Surfaces take the accent; words written white on them turn dark,
        // because white does not read on yellow.
        $this->assertStringContainsString('.bg-wine-500,', $css);
        $this->assertStringContainsString('.bg-wine-500.text-white{color:'.Brand::INK.'}', $css);
        $this->assertStringContainsString('.btn-primary,.btn-sky,.btn-action,.btn-gold,.badge-wine,.badge-gold,.section-wine{background-color:#FACC15', $css);

        // Every shade a link or heading is written in reads on a white page.
        foreach (['', '-500', '-600', '-700', '-800', '-900'] as $shade) {
            preg_match('/\.text-wine'.preg_quote($shade, '/').',[^{]*\{color:(#[0-9A-F]{6})\}/', $css, $match);
            $this->assertNotEmpty($match, "No rule for text-wine{$shade}.");
            $this->assertGreaterThanOrEqual(Contrast::AA, Contrast::ratio($match[1], '#FFFFFF'), "text-wine{$shade} is {$match[1]}.");
        }

        $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $this->get($this->at($this->coral, '/en/packages/coral-ramadan'))->assertOk()->assertSee('background-color:#FACC15', false);
    }

    public function test_a_colour_that_is_not_a_colour_never_reaches_the_page(): void
    {
        // Seven characters, the column's width: MySQL refuses anything
        // longer outright, so this is the worst a row can actually hold.
        $this->coral->site->forceFill(['colour_accent' => 'red;}a{', 'colour_primary' => 'url(x)'])->save();

        $this->get($this->at($this->coral))->assertOk()
            ->assertDontSee('red;}a{', false)
            ->assertDontSee('url(x)', false)
            ->assertSee('--site-accent: '.Brand::INK, false);
    }

    public function test_each_theme_opens_its_own_way_and_orders_the_home_page(): void
    {
        $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $this->coral->site->setTranslation('about', 'en', 'Pilgrims since 2009.')->save();

        foreach ([
            CompanySite::CLASSIC => ['Upcoming trips', 'About us', 'How to reach us'],
            CompanySite::MINIMAL => ['About us', 'Upcoming trips', 'How to reach us'],
            CompanySite::BOLD => ['Upcoming trips', 'How to reach us', 'About us'],
        ] as $theme => $order) {
            $this->coral->site->forceFill(['theme' => $theme])->save();

            $html = $this->get($this->at($this->coral))->assertOk()->assertSee('Coral Umrah')->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), "{$theme} has one heading of the first level.");
            $this->assertTrue(
                strpos($html, $order[0]) < strpos($html, $order[1]) && strpos($html, $order[1]) < strpos($html, $order[2]),
                "{$theme} tells the home page in its own order.",
            );
        }
    }

    public function test_the_sitemap_and_robots_are_the_companys_own(): void
    {
        $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $this->sellable(null, 'Rihla Signature Umrah', 'rihla-signature');

        // Rihla's first, so a shared cache would hand it to Coral.
        $this->get(config('app.url').'/sitemap.xml')->assertOk()->assertSee('rihla-signature')->assertDontSee('coral-ramadan');

        $this->get($this->at($this->coral, '/sitemap.xml'))->assertOk()
            ->assertSee($this->at($this->coral, '/en/packages/coral-ramadan'))
            ->assertSee($this->at($this->coral, '/dv/contact'))
            ->assertDontSee('rihla-signature')
            ->assertDontSee('/trips')
            ->assertDontSee('/articles')
            ->assertDontSee(config('app.url').'/', false);

        $this->get($this->at($this->coral, '/robots.txt'))->assertOk()
            ->assertSee('Sitemap: '.$this->at($this->coral, '/sitemap.xml'))
            ->assertDontSee('rihla.mv');
    }

    // ── Phase 4c: checkout, the portal and links, on the company's address ──

    private function priced(Package $package): Departure
    {
        return app(CompanyContext::class)->as((int) $package->company_id, function () use ($package): Departure {
            $departure = $package->departures()->sole();
            PriceTier::create(['departure_id' => $departure->id, 'occupancy' => 'quad', 'pax_type' => PriceTier::ADULT, 'amount_minor' => 2_850_000]);

            return $departure;
        });
    }

    public function test_a_visitor_books_on_the_companys_site_and_the_booking_is_the_companys(): void
    {
        $package = $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
        $departure = $this->priced($package);
        $this->coral->forceFill(['bank_name' => 'Bank of Maldives', 'bank_account_name' => 'Coral Umrah Pvt Ltd', 'bank_accounts' => ['MVR' => '7730000123456']])->save();

        $this->post($this->at($this->coral, '/en/packages/coral-ramadan/book'), ['departure' => $departure->id, 'occupancy' => 'quad', 'seats' => 1])
            ->assertRedirect($this->at($this->coral, '/en/book/travellers'));
        $this->post($this->at($this->coral, '/en/book/travellers'), [
            'contact_name' => 'Aminath', 'contact_phone' => '7779999', 'contact_email' => 'aminath@example.mv',
            'travellers' => [['full_name' => 'Aminath Ali', 'date_of_birth' => now()->subYears(35)->toDateString(), 'gender' => 'female']],
        ])->assertRedirect($this->at($this->coral, '/en/book/review'));

        $this->get($this->at($this->coral, '/en/book/review'))->assertOk()
            ->assertSee('understand that Coral Umrah will contact me')
            ->assertDontSee('Rihla');
        $this->post($this->at($this->coral, '/en/book/review'), ['confirmed' => '1'])
            ->assertRedirect($this->at($this->coral, '/en/book/confirmation'));

        $this->get($this->at($this->coral, '/en/book/confirmation'))->assertOk()
            ->assertSee('7730000123456')
            ->assertSee(rawurlencode('Hello Coral Umrah'), false)
            ->assertDontSee('Rihla');

        $booking = app(CompanyContext::class)->acrossEveryCompany(fn () => Booking::query()->sole());
        $this->assertSame($this->coral->id, $booking->company_id);
        $this->assertSame($this->coral->id, $booking->customer->company_id);
    }

    public function test_the_portal_opens_on_the_companys_address_and_nowhere_else(): void
    {
        $booking = app(CompanyContext::class)->as($this->coral->id, function (): Booking {
            $package = $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
            $booking = Booking::factory()->create(['customer_id' => Customer::factory()->create()->id, 'departure_id' => $package->departures()->sole()->id, 'seats' => 1]);
            $booking->forceFill(['status' => Booking::CONFIRMED])->save();

            return $booking;
        });
        $token = app(CompanyContext::class)->as($this->coral->id, fn () => app(Gatekeeper::class)->issue($booking));

        // rihla.mv cannot find another company's booking.
        $this->get(config('app.url').'/en/portal/enter/'.$token)->assertRedirect();
        $this->get(config('app.url').'/en/portal')->assertRedirect(config('app.url').'/en/portal/locked');

        $this->get($this->at($this->coral, '/en/portal/enter/'.$token))->assertRedirect($this->at($this->coral, '/en/portal'));
        $this->get($this->at($this->coral, '/en/portal'))->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('Coral Umrah')
            ->assertDontSee('Rihla Travels')
            ->assertDontSee('/learning', false);
    }

    public function test_links_made_in_the_panel_point_at_the_companys_own_site(): void
    {
        $booking = app(CompanyContext::class)->as($this->coral->id, function (): Booking {
            $package = $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');

            return Booking::factory()->create(['customer_id' => Customer::factory()->create()->id, 'departure_id' => $package->departures()->sole()->id, 'seats' => 1]);
        });

        $link = SiteAddress::route('portal.enter', ['locale' => 'en', 'token' => 'abc'], $booking->company_id);
        $this->assertSame($this->at($this->coral, '/en/portal/enter/abc'), $link);

        // Rihla's links are made as they always were.
        $this->assertSame(url('/en/portal/enter/abc'), SiteAddress::route('portal.enter', ['locale' => 'en', 'token' => 'abc'], null));

        // A company with no live site gets no link at all, never one to rihla.mv.
        $this->coral->forceFill(['site_enabled' => false])->save();
        $this->assertNull(SiteAddress::route('portal.enter', ['locale' => 'en', 'token' => 'abc'], $booking->company_id));
    }

    public function test_a_waiting_list_claim_is_signed_for_the_companys_address_and_works_there(): void
    {
        $entry = app(CompanyContext::class)->as($this->coral->id, function (): WaitlistEntry {
            $package = $this->sellable($this->coral, 'Coral Ramadan Umrah', 'coral-ramadan');
            $departure = $package->departures()->sole();
            $waitlist = app(Waitlist::class);
            $entry = $waitlist->join($departure, Customer::factory()->create(), 1);
            $waitlist->offerAvailableSeats($departure);

            return $entry->fresh();
        });

        $url = app(CompanyContext::class)->as($this->coral->id, fn () => app(Waitlist::class)->claimUrl($entry));

        $this->assertStringStartsWith($this->at($this->coral, '/en/waitlist/claim/'.$entry->id), (string) $url);
        $this->assertNotSame(403, $this->get((string) $url)->status(), 'The signature holds on the company\'s own host.');
        // On rihla.mv the same link opens nothing: the entry is not Rihla's,
        // and the signature does not cover that host either.
        $this->assertContains($this->get(str_replace($this->coral->slug.'.'.self::DOMAIN, 'localhost', (string) $url))->status(), [403, 404]);
    }

    // ── Phase 4d: each company's installable app ─────────────────────────

    public function test_the_installable_app_is_the_companys_own(): void
    {
        $home = $this->get($this->at($this->coral))->assertOk();
        $home->assertSee('rel="manifest" href="'.$this->at($this->coral, '/manifest.webmanifest').'"', false)
            ->assertSee($this->at($this->coral, '/site-sw.js'), false);

        $manifest = $this->get($this->at($this->coral, '/manifest.webmanifest'))->assertOk()->json();
        $this->assertSame('Coral Umrah', $manifest['name']);
        $this->assertSame('#14532D', $manifest['theme_color']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/site-icon-512.png', $manifest['icons'][2]['src']);
        $this->assertStringNotContainsString('Rihla', (string) json_encode($manifest));

        $worker = $this->get($this->at($this->coral, '/site-sw.js'))->assertOk()
            ->assertHeader('Content-Type', 'application/javascript')
            ->getContent();
        $this->assertStringContainsString('"site-coral-umrah-v1"', (string) $worker);
        $this->assertStringNotContainsString('rihla', strtolower((string) $worker));

        $this->get($this->at($this->coral, '/offline'))->assertOk()->assertSee('Coral Umrah')->assertDontSee('Rihla');

        // None of it on rihla.mv, which keeps its own files.
        foreach (['/manifest.webmanifest', '/site-sw.js', '/offline', '/site-icon-192.png'] as $path) {
            $this->get(config('app.url').$path)->assertNotFound();
        }
    }

    public function test_the_icon_is_the_logo_or_the_initials_at_the_size_asked(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        // No logo: the initials on the company's colour.
        $png = $this->get($this->at($this->coral, '/site-icon-192.png'))->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
        $image = imagecreatefromstring((string) $png);
        $this->assertSame([192, 192], [imagesx($image), imagesy($image)]);
        $this->assertSame(0x14532D, imagecolorat($image, 2, 2) & 0xFFFFFF, 'The corner is the company\'s colour.');

        // A logo: drawn on white.
        $logo = imagecreatetruecolor(40, 20);
        imagefill($logo, 0, 0, (int) imagecolorallocate($logo, 200, 0, 0));
        ob_start();
        imagepng($logo);
        Storage::disk('public')->put('company-sites/logo.png', (string) ob_get_clean());
        $this->coral->site->forceFill(['logo_path' => 'company-sites/logo.png'])->save();

        $image = imagecreatefromstring((string) $this->get($this->at($this->coral, '/site-icon-512.png'))->assertOk()->getContent());
        $this->assertSame([512, 512], [imagesx($image), imagesy($image)]);
        $this->assertSame(0xFFFFFF, imagecolorat($image, 2, 2) & 0xFFFFFF);
        $this->assertSame(0xC80000, imagecolorat($image, 256, 256) & 0xFFFFFF, 'The logo sits in the middle.');

        $this->get($this->at($this->coral, '/site-icon-64.png'))->assertNotFound();
    }
}
