<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DocumentVersion;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Services\Payments\SlipVault;
use App\Support\Access;
use App\Support\EncryptedFile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Identity documents and payment slips, encrypted on disk — §10.4.
 *
 * Two properties, and the second is the one that would break the site:
 *
 * 1. **What lands on disk is not the passport scan.** A backup of
 *    `storage/` taken without `.env` — which is most backups — carries
 *    ciphertext.
 * 2. **Files written before this existed still download.** Every payload
 *    says which it is, so there is no window between deploying this and
 *    running the backfill in which somebody's passport is unreachable.
 *
 * What this does *not* protect against is somebody who has both the files
 * and `APP_KEY`, which on cPanel is anybody with a shell. It is a layer,
 * not a safe, and `App\Support\EncryptedFile` says so where somebody
 * reading the code will see it.
 */
class EncryptionAtRestTest extends TestCase
{
    use RefreshDatabase;

    private const SCAN = 'Pretend passport scan bytes, standing in for a real one.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
    }

    private function traveller(): Traveller
    {
        return Traveller::factory()->create(['customer_id' => Customer::factory()->create()->getKey()]);
    }

    private function storeScan(string $contents = self::SCAN): DocumentVersion
    {
        return app(DocumentWallet::class)->store(
            $this->traveller(),
            UploadedFile::fake()->createWithContent('passport.pdf', $contents),
            'travel',
            'passport',
        );
    }

    // ── What lands on disk ───────────────────────────────────────────────

    public function test_a_passport_scan_is_ciphertext_on_disk(): void
    {
        $version = $this->storeScan();

        $raw = (string) Storage::disk($version->disk)->get($version->path);

        $this->assertStringNotContainsString('passport scan bytes', $raw);
        $this->assertTrue(EncryptedFile::looksEncrypted($raw));
    }

    public function test_it_reads_back_byte_for_byte(): void
    {
        $version = $this->storeScan();

        $this->assertSame(self::SCAN, EncryptedFile::contents($version->disk, $version->path));
    }

    /**
     * The checksum describes the scan, not the ciphertext.
     *
     * Otherwise "is this the same document as last time" stops working —
     * the encryption is salted, so the same file encrypts differently
     * every time and every re-upload would look like a new version.
     */
    public function test_the_checksum_and_size_describe_the_plaintext(): void
    {
        $version = $this->storeScan();

        $this->assertSame(hash('sha256', self::SCAN), $version->checksum);
        $this->assertSame(strlen(self::SCAN), $version->size_bytes);
    }

    /** And so re-uploading the same file is still recognised as the same. */
    public function test_the_same_file_uploaded_twice_is_still_one_version(): void
    {
        $traveller = $this->traveller();
        $wallet = app(DocumentWallet::class);

        $first = $wallet->store($traveller, UploadedFile::fake()->createWithContent('p.pdf', self::SCAN), 'travel', 'passport');
        $second = $wallet->store($traveller, UploadedFile::fake()->createWithContent('p.pdf', self::SCAN), 'travel', 'passport');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, $first->document->versions()->count());
    }

    public function test_a_payment_slip_is_ciphertext_too(): void
    {
        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(20)->create([
                'package_id' => Package::factory()->create()->getKey(),
            ])->getKey(),
            'status' => Booking::CONFIRMED,
        ]);

        $payment = Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'status' => Payment::PENDING,
        ]);

        app(SlipVault::class)->attach(
            $payment,
            UploadedFile::fake()->createWithContent('slip.pdf', 'Bank account and a name.'),
        );

        $payment->refresh();

        $raw = (string) Storage::disk((string) $payment->slip_disk)->get((string) $payment->slip_path);

        $this->assertStringNotContainsString('Bank account', $raw);
        $this->assertTrue(EncryptedFile::looksEncrypted($raw));
    }

    // ── Old files keep working ───────────────────────────────────────────

    /**
     * The property that would otherwise take the site down.
     *
     * Between deploying this and running the backfill, every file on the
     * server is plaintext. If reading assumed ciphertext, nobody could open
     * a passport in that window.
     */
    public function test_a_file_written_before_encryption_existed_still_reads(): void
    {
        Storage::disk('documents')->put('legacy/scan.pdf', self::SCAN);

        $this->assertSame(self::SCAN, EncryptedFile::contents('documents', 'legacy/scan.pdf'));
    }

    public function test_switching_encryption_off_leaves_encrypted_files_readable(): void
    {
        $version = $this->storeScan();

        config(['documents.encrypt_at_rest' => false]);

        // Every payload says which it is, so turning the setting off does
        // not orphan what was written while it was on.
        $this->assertSame(self::SCAN, EncryptedFile::contents($version->disk, $version->path));
    }

    public function test_switching_it_off_writes_plaintext(): void
    {
        config(['documents.encrypt_at_rest' => false]);

        $version = $this->storeScan();

        $this->assertFalse(EncryptedFile::looksEncrypted(
            (string) Storage::disk($version->disk)->get($version->path),
        ));
    }

    /** A marker on something that will not decrypt is an error, not rubbish. */
    public function test_a_corrupt_encrypted_payload_throws_rather_than_serving_nonsense(): void
    {
        Storage::disk('documents')->put('broken.pdf', EncryptedFile::MAGIC.'not-valid-ciphertext');

        $this->expectException(DecryptException::class);

        EncryptedFile::contents('documents', 'broken.pdf');
    }

    /** The marker cannot be the first bytes of a real document. */
    public function test_the_marker_does_not_collide_with_a_real_file_header(): void
    {
        foreach (['%PDF-1.7', "\xFF\xD8\xFF\xE0", "\x89PNG\r\n", 'ftypheic'] as $header) {
            $this->assertFalse(
                EncryptedFile::looksEncrypted($header),
                'A real file header was mistaken for ciphertext.',
            );
        }
    }

    // ── Downloading ──────────────────────────────────────────────────────

    public function test_a_download_returns_the_scan_and_not_the_ciphertext(): void
    {
        $version = $this->storeScan();

        $staff = User::factory()->create()->assignRole(Access::VISA_STAFF);

        $response = $this->actingAs($staff)
            ->get(app(DocumentWallet::class)->downloadUrl($version));

        $response->assertSuccessful();

        $this->assertSame(self::SCAN, $response->streamedContent());
    }

    // ── The backfill ─────────────────────────────────────────────────────

    public function test_the_backfill_converts_plaintext_and_leaves_ciphertext_alone(): void
    {
        config(['documents.encrypt_at_rest' => false]);
        $plain = $this->storeScan('An older scan, written before this existed.');

        config(['documents.encrypt_at_rest' => true]);
        $already = $this->storeScan('A newer scan.');

        $this->artisan('documents:encrypt')
            ->expectsOutputToContain('Encrypted 1 file(s). 1 already encrypted')
            ->assertSuccessful();

        $this->assertTrue(EncryptedFile::looksEncrypted(
            (string) Storage::disk($plain->disk)->get($plain->path),
        ));

        // And it still reads back as what it was.
        $this->assertSame(
            'An older scan, written before this existed.',
            EncryptedFile::contents($plain->disk, $plain->path),
        );

        $this->assertSame('A newer scan.', EncryptedFile::contents($already->disk, $already->path));
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $this->storeScan();

        $this->artisan('documents:encrypt')->assertSuccessful();

        $this->artisan('documents:encrypt')
            ->expectsOutputToContain('Encrypted 0 file(s). 1 already encrypted')
            ->assertSuccessful();
    }

    /**
     * Converting while the setting is off would leave the application
     * writing plaintext beside files it had just encrypted.
     */
    public function test_the_backfill_refuses_while_encryption_is_switched_off(): void
    {
        config(['documents.encrypt_at_rest' => false]);

        $this->artisan('documents:encrypt')
            ->expectsOutputToContain('Refusing to run')
            ->assertFailed();
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        config(['documents.encrypt_at_rest' => false]);
        $plain = $this->storeScan();
        config(['documents.encrypt_at_rest' => true]);

        $this->artisan('documents:encrypt', ['--dry-run' => true])->assertSuccessful();

        $this->assertFalse(EncryptedFile::looksEncrypted(
            (string) Storage::disk($plain->disk)->get($plain->path),
        ));
    }
}
