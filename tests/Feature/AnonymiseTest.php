<?php

namespace Tests\Feature;

use App\Console\Commands\Anonymise;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Enquiry;
use App\Models\Package;
use App\Models\Partner;
use App\Models\PortalAccess;
use App\Models\Property;
use App\Models\Traveller;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use App\Support\Anonymisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The anonymising export — §10.4, and overdue since Phase 3.
 *
 * `test.rihla.mv` auto-deploys from `main` and is reachable on the public
 * internet. The moment somebody restores a production backup onto it to
 * reproduce a bug, real passport numbers are on a public host.
 *
 * Three properties matter, and all three fail *safe* rather than quietly:
 *
 * 1. **It cannot run on production**, with no override.
 * 2. **It refuses when the schema has moved.** An unclassified table is
 *    the newest table, which is the one most likely to hold a passport
 *    number, so scrubbing what is recognised and leaving the rest is the
 *    worst available behaviour.
 * 3. **It leaves nothing anybody could mistake for real**, including the
 *    tokens that open a portal and the sessions that are live credentials.
 */
class AnonymiseTest extends TestCase
{
    use RefreshDatabase;

    private function somebodyReal(): Customer
    {
        $customer = Customer::factory()->create([
            'name' => 'Aishath Real Person',
            'email' => 'aishath@realdomain.example',
            'phone' => '7712345',
            'national_id' => 'A123456',
            'address' => 'A real address nobody should see on a test box',
        ]);

        Traveller::factory()->create([
            'customer_id' => $customer->getKey(),
            'full_name' => 'Aishath Real Person',
            'passport_number' => 'MV1234567',
            'medical_notes' => 'A real medical note',
            'emergency_contact_name' => 'Ibrahim Real Person',
            'emergency_contact_phone' => '9998888',
        ]);

        return $customer;
    }

    // ── The guard that has no override ───────────────────────────────────

    /**
     * A destructive command with an escape hatch is a command that will one
     * day be run with the escape hatch, by somebody sure this is the test
     * box. So there is not one.
     */
    public function test_it_refuses_on_production_even_with_force(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('data:anonymise', ['--force' => true])
            ->expectsOutputToContain('Refusing to run: APP_ENV is production.')
            ->assertFailed();
    }

    public function test_production_survives_the_attempt_untouched(): void
    {
        $customer = $this->somebodyReal();

        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('data:anonymise', ['--force' => true])->assertFailed();

        $this->assertSame('Aishath Real Person', $customer->fresh()->name);
    }

    // ── The guard that fails when the schema moves ───────────────────────

    /**
     * Every table in the real schema is classified. This is the assertion
     * that makes the command trustworthy a year from now: add a table and
     * forget it, and this fails here rather than on a public server.
     */
    public function test_every_table_in_the_schema_is_classified(): void
    {
        $unclassified = Anonymisation::unclassified($this->tables());

        $this->assertSame(
            [],
            $unclassified,
            'Unclassified: '.implode(', ', $unclassified)
            .'. Add each to SCRUB, EMPTY_OUT or KEEP in App\Support\Anonymisation.',
        );
    }

    /** And the command itself stops, rather than doing its best. */
    public function test_an_unclassified_table_stops_the_whole_run(): void
    {
        Schema::create('a_table_nobody_classified', function ($table): void {
            $table->id();
            $table->string('passport_number')->nullable();
        });

        $customer = $this->somebodyReal();

        $this->artisan('data:anonymise', ['--force' => true])
            ->expectsOutputToContain('a_table_nobody_classified')
            ->assertFailed();

        // Nothing was scrubbed — it stopped before touching anything.
        $this->assertSame('Aishath Real Person', $customer->fresh()->name);

        Schema::drop('a_table_nobody_classified');
    }

    // ── What it actually does ────────────────────────────────────────────

    public function test_it_replaces_names_passports_and_contact_details(): void
    {
        $customer = $this->somebodyReal();

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $scrubbed = $customer->fresh();

        $this->assertStringContainsString('Placeholder Person', (string) $scrubbed->name);
        $this->assertStringContainsString('@example.invalid', (string) $scrubbed->email);
        $this->assertStringNotContainsString('Aishath', (string) $scrubbed->name);
        $this->assertStringNotContainsString('7712345', (string) $scrubbed->phone);
        $this->assertStringNotContainsString('A123456', (string) $scrubbed->national_id);

        $traveller = Traveller::sole();

        $this->assertStringNotContainsString('MV1234567', (string) $traveller->passport_number);
        $this->assertNull($traveller->medical_notes);
        $this->assertStringNotContainsString('Ibrahim', (string) $traveller->emergency_contact_name);
    }

    /**
     * The stand-in is obviously a stand-in, and keyed to the row.
     *
     * Two customers must not both become "Placeholder Person", or the test
     * data stops being usable for the thing it is for — and nobody must be
     * able to read a name on the test server and act on it.
     */
    public function test_each_row_gets_its_own_recognisable_stand_in(): void
    {
        $one = Customer::factory()->create(['name' => 'First Real']);
        $two = Customer::factory()->create(['name' => 'Second Real']);

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $this->assertNotSame($one->fresh()->name, $two->fresh()->name);
        $this->assertSame('Placeholder Person '.$one->getKey(), $one->fresh()->name);
    }

    /** A stray send from the test server must reach nobody — RFC 2606. */
    public function test_scrubbed_email_addresses_cannot_be_delivered_to(): void
    {
        $this->assertStringEndsWith('@example.invalid', (string) Anonymise::standIn('email', 7));
    }

    /** And a scrubbed telephone number is not anybody's. */
    public function test_scrubbed_telephone_numbers_are_outside_the_maldivian_ranges(): void
    {
        $phone = (string) Anonymise::standIn('phone', 12345);

        $this->assertSame(7, strlen($phone));
        $this->assertStringStartsWith('3', $phone);
    }

    /**
     * A portal link somebody was sent for a real booking must not open a
     * scrubbed one on a public host.
     */
    public function test_portal_tokens_are_regenerated_so_old_links_stop_working(): void
    {
        $customer = $this->somebodyReal();
        $package = Package::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->getKey(),
            'departure_id' => Departure::factory()->withSeats(20)->create([
                'package_id' => $package->getKey(),
            ])->getKey(),
            'status' => Booking::CONFIRMED,
        ]);

        $token = 'a-real-portal-token-somebody-was-sent';

        $access = PortalAccess::create([
            'booking_id' => $booking->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $this->assertNotSame(hash('sha256', $token), $access->fresh()->token_hash);
    }

    /**
     * An audit log full of "Placeholder Person 41" reads as evidence.
     *
     * Emptying it is the honest outcome: the record of who did what to real
     * people has no business on a test box in any form.
     */
    public function test_the_audit_log_and_sessions_are_emptied_rather_than_scrubbed(): void
    {
        DB::table('audit_logs')->insert([
            'auditable_type' => Customer::class,
            'auditable_id' => 1,
            'event' => 'updated',
            'user_name' => 'A Real Member Of Staff',
            'user_email' => 'staff@rihla.example',
            'ip_address' => '203.0.113.7',
            'created_at' => now(),
        ]);

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('sessions')->count());
    }

    /** Staff accounts are scrubbed too, and none is left signable-in. */
    public function test_staff_accounts_are_scrubbed_and_no_known_credential_is_minted(): void
    {
        $user = User::factory()->create([
            'name' => 'A Real Administrator',
            'email' => 'admin@rihla.example',
        ]);

        $before = $user->password;

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $after = $user->fresh();

        $this->assertStringNotContainsString('Real Administrator', (string) $after->name);
        $this->assertStringNotContainsString('rihla.example', (string) $after->email);

        // The hash is left alone on purpose: setting it would mint a
        // credential that works on a public host.
        $this->assertSame($before, $after->password);
    }

    // ── The dry run ──────────────────────────────────────────────────────

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        $customer = $this->somebodyReal();
        Enquiry::factory()->create(['name' => 'Another Real Person']);

        $this->artisan('data:anonymise', ['--dry-run' => true])
            ->expectsOutputToContain('Would scrub')
            ->assertSuccessful();

        $this->assertSame('Aishath Real Person', $customer->fresh()->name);
        $this->assertSame('Another Real Person', Enquiry::sole()->name);
    }

    /** What is kept is kept — a scrub that empties the packages is useless. */
    public function test_it_leaves_the_things_that_are_not_about_a_person(): void
    {
        $package = Package::factory()->create();
        Departure::factory()->withSeats(20)->create(['package_id' => $package->getKey()]);

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, Package::count());
        $this->assertSame(1, Departure::count());
    }

    /** @return list<string> */
    /**
     * A `null` strategy on a NOT NULL column fails the whole run.
     *
     * `document_versions.path` is NOT NULL and was mapped to `null`, so
     * `data:anonymise` threw an integrity-constraint violation on **any
     * database holding a single document** — which is every real one. The
     * suite stayed green because no test here had ever stored a document,
     * so the command that exists to keep passport numbers off a public
     * server would have failed the first time it was run in earnest.
     *
     * Checked against the live schema rather than a list, because the trap
     * is a column somebody makes NOT NULL later.
     */
    public function test_every_column_emptied_outright_is_one_the_schema_allows_to_be_null(): void
    {
        $offenders = [];

        foreach (Anonymisation::SCRUB as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $strategy) {
                if ($strategy !== 'null' || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                if (! $this->isNullable($table, $column)) {
                    $offenders[] = $table.'.'.$column;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These columns are mapped to the `null` strategy but the schema will not accept null,',
                'so the scrub throws and nothing is scrubbed at all. Use `gone`, `text` or `name`:'],
            $offenders,
        )));
    }

    /**
     * And the command survives the thing that broke it.
     *
     * A stored document and a stored slip, which is what every real
     * database has and what no test here had.
     */
    public function test_it_survives_a_database_that_actually_holds_documents(): void
    {
        Storage::fake('documents');

        $customer = $this->somebodyReal();

        app(DocumentWallet::class)->store(
            Traveller::factory()->create(['customer_id' => $customer->getKey()]),
            UploadedFile::fake()->createWithContent('passport.pdf', 'A real passport scan.'),
            'travel',
            'passport',
        );

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $version = DB::table('document_versions')->first();

        $this->assertNotNull($version);
        $this->assertNotSame('passport.pdf', $version->original_filename);
    }

    /**
     * And a guesthouse partner, whose record is the newest place in this
     * schema holding somebody's real phone number — §15.4 (Phase 9.1).
     *
     * A fixture that never stores one is not a passing test: the whole
     * scrub list could name the wrong column and every assertion above
     * would still be green, because no partner ever existed in any of them.
     */
    public function test_a_guesthouse_partner_keeps_nothing_that_identifies_them(): void
    {
        $partner = Partner::factory()->create([
            'name' => 'Maafushi View Guest House',
            'contact_name' => 'Ibrahim Waheed',
            'phone' => '+9607771234',
            'whatsapp' => '+9607771234',
            'email' => 'ibrahim@maafushiview.mv',
            'contract_notes' => 'Signed 12 March. Twelve rooms on allotment until October.',
        ]);

        // The building itself is product content and stays legible, so the
        // test server is still worth looking at.
        $property = Property::factory()->create([
            'partner_id' => $partner->getKey(),
            'name' => ['en' => 'Maafushi View'],
        ]);

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $scrubbed = $partner->fresh();

        $this->assertNotSame('Ibrahim Waheed', $scrubbed->contact_name);
        $this->assertNotSame('+9607771234', $scrubbed->phone);
        $this->assertNotSame('+9607771234', $scrubbed->whatsapp);
        $this->assertNotSame('ibrahim@maafushiview.mv', $scrubbed->email);
        $this->assertStringNotContainsString('Signed 12 March', (string) $scrubbed->contract_notes);

        $this->assertSame('Maafushi View', $property->fresh()->getTranslation('name', 'en'));
    }

    private function isNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $described) {
            if ($described['name'] === $column) {
                return (bool) $described['nullable'];
            }
        }

        return true;
    }

    private function tables(): array
    {
        return collect(Schema::getTableListing())
            ->map(fn (string $table): string => Str::afterLast($table, '.'))
            ->reject(fn (string $table): bool => str_starts_with($table, 'sqlite_'))
            ->values()
            ->all();
    }
}
