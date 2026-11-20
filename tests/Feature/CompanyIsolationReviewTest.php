<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Partner;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use App\Support\Assignees;
use App\Support\CompanyContext;
use App\Support\CompanyDetails;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Companies\Services\AddCompany;
use Modules\Companies\Services\CompanyExport;
use Modules\Companies\Services\CompanyTeam;
use Modules\Companies\Services\SupportAccess;
use Modules\Content\Models\AssistantExchange;
use Modules\Telegram\Services\Notifier;
use Tests\TestCase;
use ZipArchive;

/**
 * Defects the security review before the pilot found where one company's
 * data met another's outside a panel request (companies plan, risks).
 * Each test failed before its fix.
 */
class CompanyIsolationReviewTest extends TestCase
{
    use RefreshDatabase;

    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('documents');
        Storage::fake('local');
        $this->context = app(CompanyContext::class);
    }

    public function test_forgetting_one_companys_customer_leaves_another_companys_enquiry_from_the_same_person(): void
    {
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');
        $b = app(AddCompany::class)('Beta Umrah', 'Owner B', 'b@example.com');
        $aCustomer = $this->context->as($a->id, fn () => Customer::factory()->create(['email' => 'same@person.mv', 'phone' => '7711111']));
        $aEnquiry = $this->context->as($a->id, fn () => Enquiry::factory()->create(['customer_id' => null, 'name' => 'A lead', 'email' => 'same@person.mv', 'phone' => '7711111']));
        $bEnquiry = $this->context->as($b->id, fn () => Enquiry::factory()->create(['customer_id' => null, 'name' => 'B lead', 'email' => 'same@person.mv', 'phone' => '7711111']));

        $this->artisan('data:forget', ['customer' => (string) $aCustomer->id])->assertSuccessful();

        $this->assertNotSame('A lead', DB::table('enquiries')->where('id', $aEnquiry->id)->value('name'), 'Their own company\'s enquiry is erased.');
        $this->assertSame('B lead', DB::table('enquiries')->where('id', $bEnquiry->id)->value('name'), 'Another company\'s is not this request\'s.');
    }

    public function test_an_address_that_is_a_customer_of_two_companies_is_refused_rather_than_guessed(): void
    {
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');
        $b = app(AddCompany::class)('Beta Umrah', 'Owner B', 'b@example.com');
        $aCustomer = $this->context->as($a->id, fn () => Customer::factory()->create(['email' => 'same@person.mv']));
        $bCustomer = $this->context->as($b->id, fn () => Customer::factory()->create(['email' => 'same@person.mv']));

        $this->artisan('data:forget', ['customer' => 'same@person.mv'])
            ->expectsOutputToContain('Run this again with the customer id')
            ->assertFailed();

        $this->assertSame('same@person.mv', DB::table('customers')->where('id', $aCustomer->id)->value('email'));
        $this->assertSame('same@person.mv', DB::table('customers')->where('id', $bCustomer->id)->value('email'));
    }

    public function test_a_portal_password_hash_never_reaches_the_audit_trail_or_the_export(): void
    {
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');
        $customer = $this->context->as($a->id, fn () => Customer::factory()->create());
        $this->context->as($a->id, fn () => $customer->forceFill(['portal_password' => Hash::make('secret-pass-123')])->save());

        $trail = DB::table('audit_logs')->where('auditable_id', $customer->id)->pluck('new_values')->join(' ');
        $this->assertStringNotContainsString('portal_password', $trail);
        $this->assertStringNotContainsString('$2y$', $trail);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($this->context->acrossEveryCompany(fn () => app(CompanyExport::class)->make($a))));
        $this->assertStringNotContainsString('$2y$', (string) $zip->getFromName('audit_logs.csv'));
        $zip->close();
    }

    public function test_the_migration_removes_hashes_already_in_the_trail(): void
    {
        $id = DB::table('audit_logs')->insertGetId([
            'event' => 'updated', 'auditable_type' => (new Customer)->getMorphClass(), 'auditable_id' => 1,
            'old_values' => null, 'new_values' => json_encode(['portal_password' => '$2y$04$abc', 'name' => 'Aminath']),
            'created_at' => now(),
        ]);

        (require database_path('migrations/2026_11_21_110000_strip_portal_password_hashes_from_the_audit_trail.php'))->up();

        $this->assertSame(['name' => 'Aminath'], json_decode((string) DB::table('audit_logs')->where('id', $id)->value('new_values'), true));
    }

    public function test_the_assistant_log_is_pruned_for_every_company(): void
    {
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');
        $this->context->as($a->id, function (): void {
            $exchange = AssistantExchange::create(['traveller_id' => Traveller::factory()->create()->id, 'question' => 'q', 'answer' => 'a', 'referred' => false, 'sources' => [], 'provider' => 'x', 'model' => 'y', 'locale' => 'en']);
            DB::table('assistant_exchanges')->where('id', $exchange->id)->update(['created_at' => now()->subDays(400)]);
        });

        $this->artisan('assistant:prune')->assertSuccessful();

        $this->assertSame(0, DB::table('assistant_exchanges')->count());
    }

    public function test_a_companys_bot_links_to_the_companys_own_panel(): void
    {
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');

        $this->assertStringEndsWith('/staff/payments', Notifier::panelUrl('payments'));
        $this->assertStringEndsWith('/company/'.$a->slug.'/payments', $this->context->as($a->id, fn (): string => Notifier::panelUrl('payments')));
    }

    public function test_a_rihla_manager_on_a_support_grant_can_look_and_change_nothing(): void
    {
        // Super Admin holds no permission rows, so SupportAccessTest could
        // not see this: an Operations Manager holds booking.create through
        // spatie, and spatie used to answer before the company gate.
        $coral = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
        $owner = User::where('email', 'coral@example.com')->sole();
        $manager = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
        $support = app(SupportAccess::class);
        $support->grant($support->ask($coral, $manager, 'Checking a booking.'), $owner, 1);

        $this->actingAs($manager)->get('/company/'.$coral->slug.'/bookings')->assertOk();
        $this->actingAs($manager)->get('/company/'.$coral->slug.'/bookings/create')->assertForbidden();
    }

    public function test_an_address_that_reads_as_a_companys_never_serves_rihlas_site(): void
    {
        config(['companies.site_domain' => 'rihla.test']);
        $coral = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
        $coral->forceFill(['site_enabled' => true, 'registration_paid_at' => now(), 'site_domain' => 'coralumrah.test'])->save();
        $coral->site->forceFill(['published_at' => now()])->save();
        $name = 'http://'.$coral->slug.'.rihla.test';

        // A trailing dot is the same address.
        $this->get($name.'./en')->assertOk()->assertSee('Coral Umrah');
        // Deeper than one level is never a site, and never rihla.mv either.
        $this->get('http://www.'.$coral->slug.'.rihla.test/en/packages')->assertNotFound();

        // Suspended: the company's own domain is a 404, not Rihla's site.
        $coral->forceFill(['status' => Partner::STATUS_SUSPENDED, 'suspended_reason' => 'Unpaid.'])->save();
        $this->get('http://coralumrah.test/en/packages')->assertNotFound();
        $this->get('http://coralumrah.test./en/packages')->assertNotFound();
    }

    public function test_inside_a_company_work_can_be_handed_only_to_its_own_people(): void
    {
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');
        app(AddCompany::class)('Beta Umrah', 'Owner B', 'b@example.com');
        $visa = app(CompanyTeam::class)->add($a, 'Mariyam Visa', 'visa@alpha.example', Access::VISA_STAFF);
        $rihla = User::factory()->create(['name' => 'Rihla Visa'])->assignRole(Access::VISA_STAFF);

        $ownerA = User::where('email', 'a@example.com')->sole();
        $this->actingAs($ownerA)->get('/company/'.$a->slug)->assertOk();
        Filament::setTenant($a);

        $anybody = Assignees::options();
        $this->assertEqualsCanonicalizing([$ownerA->id, $visa->user_id], array_keys($anybody), 'Not Beta\'s owner, not Rihla\'s staff.');
        $this->assertEqualsCanonicalizing([$ownerA->id, $visa->user_id], array_keys(Assignees::options([Access::VISA_STAFF])), 'Their own visa staff, and the Owner.');
        $this->assertArrayNotHasKey($rihla->id, Assignees::options([Access::VISA_STAFF]));
    }

    public function test_rihlas_tax_line_is_never_printed_under_a_companys_name(): void
    {
        config(['invoices.tax.note' => 'GST TIN 1000999GST001']);
        $a = app(AddCompany::class)('Alpha Umrah', 'Owner A', 'a@example.com');

        $this->assertSame('GST TIN 1000999GST001', CompanyDetails::taxNote());
        $this->assertNull($this->context->as($a->id, fn (): ?string => CompanyDetails::taxNote()));
    }
}
