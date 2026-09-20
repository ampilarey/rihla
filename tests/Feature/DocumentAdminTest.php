<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The screen the wallet is used through.
 *
 * A Filament page can throw while the request still returns 200 — each
 * action loads in its own Livewire request — so these render the pages
 * rather than checking status codes.
 */
class DocumentAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function file(string $contents = 'scan', string $name = 'passport.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function document(string $contents = 'scan'): Document
    {
        return app(DocumentWallet::class)->store(
            Traveller::factory()->create(['full_name' => 'Aminath Ibrahim']),
            $this->file($contents),
            Document::IDENTITY,
            Document::PASSPORT,
        )->document;
    }

    // ── Who may look ──────────────────────────────────────────────────────

    public function test_visa_staff_can_list_documents(): void
    {
        $this->document();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListDocuments::class)
            ->assertOk()
            ->assertSee('Aminath Ibrahim')
            ->assertSee('passport');
    }

    /** This is the role's whole job, and it held nothing but panel access before. */
    public function test_visa_staff_can_reach_the_screen_over_http(): void
    {
        $this->actingAs($this->staff(Access::VISA_STAFF))->get('/staff/documents')->assertOk();
    }

    public function test_the_content_manager_cannot(): void
    {
        $this->actingAs($this->staff(Access::CONTENT_MANAGER))->get('/staff/documents')->assertForbidden();
    }

    /** Seeing a document exists is not the same as pulling the scan. */
    public function test_the_download_action_is_hidden_without_the_permission(): void
    {
        $document = $this->document();

        Livewire::actingAs($this->staff(Access::PILGRIM_SUPPORT))
            ->test(ListDocuments::class)
            ->assertTableActionHidden('download', $document);

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListDocuments::class)
            ->assertTableActionVisible('download', $document);
    }

    // ── Uploading ─────────────────────────────────────────────────────────

    public function test_a_document_can_be_added(): void
    {
        $traveller = Traveller::factory()->create();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(CreateDocument::class)
            ->fillForm([
                'traveller_id' => $traveller->getKey(),
                'type' => Document::PASSPORT,
                'category' => Document::IDENTITY,
                'upload' => $this->file('a real scan'),
                'expires_at' => now()->addYears(3)->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $document = Document::sole();

        $this->assertSame($traveller->getKey(), $document->traveller_id);
        $this->assertSame(1, $document->versions()->count());
        $this->assertSame(hash('sha256', 'a real scan'), $document->versions()->sole()->checksum);
    }

    /** [R-8] through the screen, not just the service. */
    public function test_uploading_again_from_the_edit_screen_adds_a_version(): void
    {
        $document = $this->document('the old passport');

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(EditDocument::class, ['record' => $document->getKey()])
            ->fillForm(['upload' => $this->file('the new passport')])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, DocumentVersion::count());
        $this->assertNotNull(DocumentVersion::where('version', 1)->sole()->superseded_at);
        $this->assertTrue(
            Storage::disk('documents')->exists(DocumentVersion::where('version', 1)->sole()->path),
            'The superseded file is kept.',
        );
    }

    public function test_editing_without_a_file_changes_no_version(): void
    {
        $document = $this->document();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(EditDocument::class, ['record' => $document->getKey()])
            ->fillForm(['notes' => 'Chased on WhatsApp'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, DocumentVersion::count());
        $this->assertSame('Chased on WhatsApp', $document->fresh()->notes);
    }

    // ── Checking ──────────────────────────────────────────────────────────

    public function test_a_document_can_be_verified(): void
    {
        $document = $this->document();
        $staff = $this->staff(Access::VISA_STAFF);

        Livewire::actingAs($staff)
            ->test(ListDocuments::class)
            ->callTableAction('verify', $document);

        $document->refresh();

        $this->assertSame(Document::VERIFIED, $document->status);
        $this->assertSame($staff->getKey(), $document->verified_by);
    }

    public function test_rejecting_records_what_was_wrong_with_it(): void
    {
        $document = $this->document();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListDocuments::class)
            ->callTableAction('reject', $document, ['reason' => 'The photo page is cut off']);

        $document->refresh();

        $this->assertSame(Document::REJECTED, $document->status);
        $this->assertSame('The photo page is cut off', $document->rejection_reason);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $document = $this->document();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListDocuments::class)
            ->callTableAction('reject', $document, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(Document::PENDING, $document->fresh()->status);
    }

    /** Nothing in the wallet may be deleted — that is [R-8]. */
    public function test_the_list_offers_no_delete(): void
    {
        $document = $this->document();

        Livewire::actingAs($this->staff(Access::VISA_STAFF))
            ->test(ListDocuments::class)
            ->assertTableActionDoesNotExist('delete');

        $this->assertModelExists($document);
    }
}
