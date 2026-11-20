<?php

namespace Modules\Companies\Tests;

use App\Models\Partner;
use App\Support\CompanyContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Content\Models\Person;
use Modules\Umrah\Models\Departure;
use Modules\Umrah\Models\Package;
use Tests\TestCase;

/**
 * Phase 1e of docs/UMRAH_COMPANIES_PLAN.md: rihla.mv shows Rihla's company
 * and nobody else's (decision 2), and a public address is unique within its
 * company rather than across all of them.
 */
class CompanyPublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coral = Partner::factory()->create(['name' => 'Coral Umrah', 'kind' => Partner::KIND_AGENCY]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asCoral(callable $callback): mixed
    {
        return app(CompanyContext::class)->as($this->coral->id, $callback);
    }

    /** A published package with an open departure, which rihla.mv would sell if it were Rihla's. */
    private function sellable(string $title): Package
    {
        $package = Package::factory()->create(['title' => ['en' => $title], 'slug' => 'coral-signature-umrah']);
        Departure::factory()->withSeats(20)->create(['package_id' => $package->id]);

        return $package;
    }

    public function test_another_companys_package_is_nowhere_on_rihla_mv(): void
    {
        $this->asCoral(fn () => $this->sellable('Coral Signature Umrah'));

        $this->get('/en/packages')->assertOk()->assertDontSee('Coral Signature Umrah');
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('coral-signature-umrah');
        $this->get('/en/packages/coral-signature-umrah')->assertNotFound();
        $this->get('/en/packages/coral-signature-umrah/book')->assertNotFound();
    }

    public function test_rihlas_own_package_is_still_sold(): void
    {
        $this->sellable('Rihla Signature Umrah');

        $this->get('/en/packages')->assertOk()->assertSee('Rihla Signature Umrah');
        $this->get('/en/packages/coral-signature-umrah')->assertOk();
    }

    public function test_two_companies_may_use_the_same_address_and_one_company_may_not_twice(): void
    {
        Package::factory()->create(['slug' => 'ramadan-umrah']);
        $theirs = $this->asCoral(fn () => Package::factory()->create(['slug' => 'ramadan-umrah']));
        $this->assertSame($this->coral->id, $theirs->company_id);

        Person::factory()->create(['slug' => 'hassan']);
        $this->asCoral(fn () => Person::factory()->create(['slug' => 'hassan']));

        $this->expectException(UniqueConstraintViolationException::class);
        Package::factory()->create(['slug' => 'ramadan-umrah']);
    }

    public function test_the_forms_unique_check_agrees_with_the_index(): void
    {
        $this->asCoral(fn () => Package::factory()->create(['slug' => 'ramadan-umrah']));

        $rule = fn () => ['slug' => CompanyContext::uniqueWithin(Rule::unique('packages', 'slug'))];

        // Their address does not stop Rihla using it...
        $this->assertTrue(Validator::make(['slug' => 'ramadan-umrah'], $rule())->passes());

        // ...but Rihla's own does.
        Package::factory()->create(['slug' => 'ramadan-umrah']);
        $this->assertTrue(Validator::make(['slug' => 'ramadan-umrah'], $rule())->fails());
    }
}
