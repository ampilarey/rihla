<?php

namespace Modules\Companies\Tests;

use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Support\Access;
use App\Support\CompanyContext;
use App\Support\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Companies\Models\CompanySite;
use Modules\Companies\Services\AddCompany;
use Modules\Companies\Services\CompanyExport;
use Tests\TestCase;
use ZipArchive;

/**
 * A company can always leave (companies plan, Phase 5d; decision 12): one
 * export of everything it holds, in open formats, with its files and none
 * of anybody else's; and an account that closes without deleting anything.
 */
class CompanyExportTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('documents');
        Storage::fake('local');
        $this->coral = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
    }

    /** @return array<string, string> file name => contents */
    private function unzip(string $relative): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($relative)));
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromName($name);
        }

        $zip->close();

        return $files;
    }

    public function test_the_export_holds_everything_of_the_companys_and_nothing_of_anybody_elses(): void
    {
        // Rihla's own customer, who must not appear.
        Customer::factory()->create(['name' => 'Rihla Customer Hassan']);

        app(CompanyContext::class)->as($this->coral->id, function (): void {
            $customer = Customer::factory()->create(['name' => 'Coral Customer Aminath', 'portal_password' => 'hashed-secret-value']);
            $traveller = Traveller::factory()->for($customer)->create(['full_name' => 'Aminath Ali', 'passport_number' => 'LA1234567']);
            app(DocumentWallet::class)->store($traveller, UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 coral scan'), 'travel', 'passport');
        });

        $files = $this->unzip(app(CompanyExport::class)->make($this->coral));

        $this->assertArrayHasKey('README.txt', $files);
        $this->assertStringContainsString('Coral Customer Aminath', $files['customers.csv']);
        $this->assertStringNotContainsString('Rihla Customer Hassan', $files['customers.csv']);

        // An identifier stored encrypted is written readable.
        $this->assertStringContainsString('LA1234567', $files['travellers.csv']);
        $this->assertStringNotContainsString('enc:', $files['travellers.csv']);

        // The scan itself, decrypted.
        $scans = array_filter($files, fn (string $name): bool => str_starts_with($name, 'files/documents/'), ARRAY_FILTER_USE_KEY);
        $this->assertSame(['%PDF-1.4 coral scan'], array_values($scans));

        // No credentials: no password column, no link tokens.
        $this->assertStringNotContainsString('portal_password', $files['customers.csv']);
        $this->assertStringNotContainsString('hashed-secret-value', $files['customers.csv']);
        $this->assertArrayNotHasKey('portal_accesses.csv', $files);
        $this->assertArrayHasKey('audit_logs.csv', $files);
    }

    public function test_rihlas_admin_downloads_it_and_the_download_is_recorded(): void
    {
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $this->coral->id])
            ->callAction('export')
            ->assertFileDownloaded();

        $this->assertTrue(AuditLog::query()
            ->where('event', AuditLog::DOWNLOADED)
            ->where('auditable_id', $this->coral->id)
            ->where('user_id', $admin->id)
            ->exists());
    }

    public function test_the_command_writes_the_same_export(): void
    {
        $this->artisan('company:export', ['company' => $this->coral->slug])->assertSuccessful();
        $this->assertNotEmpty(Storage::disk('local')->files('exports'));

        $this->artisan('company:export', ['company' => 'nobody'])->assertFailed();
    }

    public function test_closing_an_account_turns_everything_off_and_deletes_nothing(): void
    {
        config(['companies.site_domain' => 'rihla.test']);
        $this->coral->forceFill(['site_enabled' => true])->save();
        $this->coral->site->forceFill(['published_at' => now()])->save();
        $customer = app(CompanyContext::class)->as($this->coral->id, fn () => Customer::factory()->create());
        $admin = User::factory()->create()->assignRole(Access::SUPER_ADMIN);
        $this->assertTrue(CurrentSite::isLive($this->coral->fresh()));

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $this->coral->id])
            ->callAction('close', data: ['reason' => 'They have stopped trading.'])
            ->assertNotified('Account closed');

        $coral = $this->coral->fresh();
        $this->assertTrue($coral->isClosed());
        $this->assertTrue($coral->isSuspended(), 'Everything that shuts a suspended company out shuts a closed one out.');
        $this->assertFalse(CurrentSite::isLive($coral));
        $this->assertTrue(DB::table('customers')->where('id', $customer->id)->exists(), 'Nothing is deleted.');
        $this->assertInstanceOf(CompanySite::class, $coral->site);

        // It can still be exported, and reopened.
        $this->assertNotEmpty(app(CompanyExport::class)->make($coral));

        Livewire::actingAs($admin)->test(EditPartner::class, ['record' => $coral->id])
            ->assertActionHidden('close')
            ->callAction('reactivate');
        $this->assertSame(Partner::STATUS_ACTIVE, $coral->fresh()->status);
        $this->assertFalse($coral->fresh()->site_enabled, 'A reopened site stays off until somebody switches it on.');
    }
}
