<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The document wallet.
 *
 * [R-8] is the point: a replaced passport creates version 2 and supersedes
 * version 1, it never overwrites one. Retrofitting that onto flat rows means
 * migrating live passport data, which is why it is here rather than later.
 */
class DocumentWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fake disk, so a test run never writes a real passport scan into
        // storage and never depends on one being there.
        Storage::fake('documents');
    }

    private function wallet(): DocumentWallet
    {
        return app(DocumentWallet::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function file(string $contents = 'a passport scan', string $name = 'passport.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function store(string $contents = 'a passport scan', ?Traveller $traveller = null): DocumentVersion
    {
        return $this->wallet()->store(
            $traveller ?? Traveller::factory()->create(),
            $this->file($contents),
            Document::IDENTITY,
            Document::PASSPORT,
        );
    }

    // ── Versioning [R-8] ──────────────────────────────────────────────────

    public function test_a_first_upload_is_version_one(): void
    {
        $version = $this->store();

        $this->assertSame(1, $version->version);
        $this->assertTrue($version->isCurrent());
        $this->assertSame(1, Document::count());
    }

    /**
     * The whole of [R-8]. A visa was applied for against a particular
     * passport; when the traveller renews mid-process, the only way to
     * answer "which document did we send them?" is to still have it.
     */
    public function test_replacing_a_document_supersedes_rather_than_overwrites(): void
    {
        $traveller = Traveller::factory()->create();

        $first = $this->store('the old passport', $traveller);
        $second = $this->store('the new passport', $traveller);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame(1, Document::count(), 'One document, two versions.');
        $this->assertSame(2, DocumentVersion::count());

        $first->refresh();

        $this->assertNotNull($first->superseded_at, 'Version 1 is superseded…');
        $this->assertTrue(Storage::disk('documents')->exists($first->path), '…and its file is still there.');
        $this->assertTrue($second->isCurrent());
    }

    public function test_the_current_version_is_the_newest_one(): void
    {
        $traveller = Traveller::factory()->create();
        $this->store('one', $traveller);
        $second = $this->store('two', $traveller);

        $document = Document::sole()->load('versions');

        $this->assertSame($second->getKey(), $document->currentVersion()->getKey());
    }

    /**
     * A customer resending on WhatsApp, or a staff member unsure the first
     * attempt worked. Nothing changed, so nothing is versioned — a wallet
     * full of identical "versions" makes the real history unreadable.
     */
    public function test_re_uploading_the_same_file_creates_no_new_version(): void
    {
        $traveller = Traveller::factory()->create();

        $first = $this->store('identical bytes', $traveller);
        $again = $this->store('identical bytes', $traveller);

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame(1, DocumentVersion::count());
    }

    public function test_every_version_is_checksummed(): void
    {
        $version = $this->store('a passport scan');

        $this->assertSame(hash('sha256', 'a passport scan'), $version->checksum);
    }

    /** Somebody has to look at the new one. */
    public function test_replacing_a_verified_document_sends_it_back_for_checking(): void
    {
        $traveller = Traveller::factory()->create();
        $this->store('first', $traveller);

        $document = Document::sole();
        $this->wallet()->verify($document, $this->staff(Access::VISA_STAFF)->getKey());
        $this->assertSame(Document::VERIFIED, $document->fresh()->status);

        $this->store('second', $traveller);

        $this->assertSame(Document::PENDING, $document->fresh()->status);
        $this->assertNull($document->fresh()->verified_at);
    }

    // ── Storage ───────────────────────────────────────────────────────────

    /** A predictable layout is one misconfiguration away from being an index. */
    public function test_the_stored_path_does_not_name_the_traveller(): void
    {
        $traveller = Traveller::factory()->create(['full_name' => 'Aminath Ibrahim']);

        $version = $this->store('scan', $traveller);

        $this->assertStringNotContainsString('Aminath', $version->path);
        $this->assertStringNotContainsString('passport.pdf', $version->path);
        $this->assertSame('passport.pdf', $version->original_filename, 'The real name is kept as data.');
    }

    /**
     * The disk must not be servable. `serve => true` would hand Laravel's
     * built-in local-disk route every passport scan in the wallet.
     */
    public function test_the_documents_disk_is_private_and_not_servable(): void
    {
        $this->assertFalse(config('filesystems.disks.documents.serve'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
    }

    // ── Downloading ───────────────────────────────────────────────────────

    public function test_a_signed_link_downloads_the_file(): void
    {
        $version = $this->store('the bytes');

        $this->actingAs($this->staff(Access::VISA_STAFF))
            ->get($this->wallet()->downloadUrl($version))
            ->assertOk()
            ->assertDownload('passport.pdf');
    }

    /** The disk is unreachable any other way, so the signature is the door. */
    public function test_an_unsigned_link_is_refused(): void
    {
        $version = $this->store();

        $this->actingAs($this->staff(Access::VISA_STAFF))
            ->get("/documents/{$version->getKey()}/download")
            ->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $version = $this->store();
        $url = $this->wallet()->downloadUrl($version);

        $this->travel(10)->minutes();

        $this->actingAs($this->staff(Access::VISA_STAFF))->get($url)->assertForbidden();
    }

    public function test_a_guest_cannot_download(): void
    {
        $version = $this->store();

        $this->get($this->wallet()->downloadUrl($version))->assertRedirect('/login');
    }

    /**
     * Seeing that a passport has been collected is a different disclosure
     * from pulling the scan, which is why download is its own permission.
     */
    public function test_a_role_without_the_download_permission_is_refused(): void
    {
        $version = $this->store();
        $support = $this->staff(Access::PILGRIM_SUPPORT);

        $this->assertTrue($support->can('document.view'), 'Pilgrim Support can see it exists…');
        $this->assertFalse($support->can('document.download'), '…and cannot pull the file.');

        $this->actingAs($support)->get($this->wallet()->downloadUrl($version))->assertForbidden();
    }

    public function test_the_content_manager_has_no_document_permission_at_all(): void
    {
        $editor = $this->staff(Access::CONTENT_MANAGER);

        foreach (['viewAny', 'view', 'create', 'update', 'download'] as $action) {
            $this->assertFalse($editor->can("document.{$action}"), "Content Manager holds document.{$action}.");
        }
    }

    /** [R-8]: every version is kept, so nobody may delete one. */
    public function test_nobody_may_delete_a_document(): void
    {
        foreach (Access::ROLES as $role) {
            if ($role === Access::SUPER_ADMIN) {
                continue;
            }

            $this->assertFalse($this->staff($role)->can('document.delete'), "[{$role}] may delete documents.");
        }
    }

    /**
     * For most records the interesting event is a change; for a passport
     * scan it is a read. "Who has had a copy of this?" is the question asked
     * after something goes wrong.
     */
    public function test_every_download_is_audited(): void
    {
        $version = $this->store();
        $staff = $this->staff(Access::VISA_STAFF);

        $this->actingAs($staff)->get($this->wallet()->downloadUrl($version))->assertOk();

        $log = AuditLog::where('event', AuditLog::DOWNLOADED)->sole();

        $this->assertSame($staff->getKey(), $log->user_id);
        $this->assertSame($version->getKey(), $log->auditable_id);
        $this->assertSame('passport.pdf', $log->new_values['original_filename']);
    }

    /** The trail records who looked, never what they looked at. */
    public function test_the_audit_row_holds_no_file_contents(): void
    {
        $version = $this->store('SECRET-PASSPORT-BYTES');

        $this->actingAs($this->staff(Access::VISA_STAFF))->get($this->wallet()->downloadUrl($version));

        $log = AuditLog::where('event', AuditLog::DOWNLOADED)->sole();

        $this->assertStringNotContainsString(
            'SECRET-PASSPORT-BYTES',
            json_encode([$log->old_values, $log->new_values]) ?: '',
        );
    }

    public function test_a_missing_file_is_a_404_not_a_broken_stream(): void
    {
        $version = $this->store();
        Storage::disk('documents')->delete($version->path);

        $this->actingAs($this->staff(Access::VISA_STAFF))
            ->get($this->wallet()->downloadUrl($version))
            ->assertNotFound();
    }

    // ── Expiry ────────────────────────────────────────────────────────────

    /**
     * Computed against a configurable window, never stored: a stored
     * "expiring soon" flag is wrong the moment the clock passes it, and the
     * plan (§5.4b) puts every Saudi requirement in configuration.
     */
    public function test_a_passport_expiring_inside_the_window_is_flagged(): void
    {
        $document = Document::factory()->create(['expires_at' => now()->addMonths(3)]);

        $this->assertTrue($document->expiresWithinWindow());
    }

    public function test_a_passport_valid_well_past_the_window_is_not(): void
    {
        $document = Document::factory()->create(['expires_at' => now()->addYears(2)]);

        $this->assertFalse($document->expiresWithinWindow());
    }

    /** Measured from the travel date, not today. */
    public function test_the_window_is_measured_from_the_departure(): void
    {
        $document = Document::factory()->create(['expires_at' => now()->addMonths(9)]);

        $this->assertFalse($document->expiresWithinWindow(), 'Fine today…');
        $this->assertTrue(
            $document->expiresWithinWindow(now()->addMonths(6)),
            '…and not fine for a departure six months out.',
        );
    }

    public function test_the_window_is_configuration_not_a_constant(): void
    {
        $document = Document::factory()->create(['expires_at' => now()->addMonths(9)]);

        config(['documents.passport_validity_months' => 12]);

        $this->assertTrue($document->expiresWithinWindow(),
            'A mid-season rule change must be a config edit, not a deployment.');
    }

    public function test_a_document_with_no_expiry_is_never_flagged(): void
    {
        $this->assertFalse(Document::factory()->create(['expires_at' => null])->expiresWithinWindow());
    }
}
