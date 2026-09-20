<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Import\CustomerImport;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importing six years of spreadsheet without creating a second copy of
 * everybody.
 *
 * The plan's risk register asks for "import in dry-run mode with a review
 * queue; duplicate detection with human merge", and most of what is tested
 * here is the refusal to guess.
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body, string $header = 'name,email,phone,national_id,address,notes'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import').'.csv';

        file_put_contents($path, $header."\n".$body);

        return $path;
    }

    private function import(): CustomerImport
    {
        return app(CustomerImport::class);
    }

    // ── The same number, written five ways ───────────────────────────────

    /**
     * The single thing this import turns on. A spreadsheet edited by four
     * people over six years holds one person's number in every one of these
     * forms, and a string comparison calls them four different people.
     */
    public function test_one_number_written_differently_is_one_number(): void
    {
        $forms = ['7712345', '+960 771 2345', '960-7712345', '00960 7712345', ' 771 23 45 '];

        foreach ($forms as $form) {
            $this->assertSame('7712345', PhoneNumber::key($form), "Failed on: {$form}");
        }

        $this->assertTrue(PhoneNumber::same('7712345', '+960 771 2345'));
    }

    /**
     * A number that merely begins with 960 is not a Maldivian number with
     * its country code on. Stripping it would invent a match between two
     * strangers.
     */
    public function test_a_longer_number_starting_960_keeps_its_digits(): void
    {
        $this->assertSame('96012345678', PhoneNumber::key('+96012345678'));
    }

    public function test_an_empty_number_matches_nothing(): void
    {
        $this->assertNull(PhoneNumber::key(''));
        $this->assertNull(PhoneNumber::key(null));
        $this->assertNull(PhoneNumber::key('n/a'));
        // Two customers with no number are not the same customer.
        $this->assertFalse(PhoneNumber::same('', ''));
    }

    // ── Deciding ─────────────────────────────────────────────────────────

    public function test_an_unknown_person_is_new(): void
    {
        $plan = $this->import()->plan($this->csv('Ibrahim Waheed,ibrahim@example.mv,7712345,A123456,Malé,'));

        $this->assertCount(1, $plan);
        $this->assertSame(CustomerImport::NEW, $plan[0]['outcome']);
    }

    public function test_a_known_number_is_matched_and_nothing_is_overwritten(): void
    {
        $existing = Customer::factory()->create([
            'name' => 'Ibrahim Waheed',
            'phone' => '+960 771 2345',
            'email' => 'ibrahim@example.mv',
        ]);

        $plan = $this->import()->plan($this->csv('Ibrahim Waheed,ibrahim@example.mv,7712345,,,'));

        $this->assertSame(CustomerImport::MATCHED, $plan[0]['outcome']);
        $this->assertSame($existing->getKey(), $plan[0]['customer_id']);
        $this->assertStringContainsString('everything agrees', $plan[0]['because']);
    }

    /**
     * Named, never applied. An import that quietly overwrites a name
     * somebody corrected last week is worse than one that says the file
     * disagrees and leaves it.
     */
    public function test_a_disagreement_is_reported_rather_than_applied(): void
    {
        $existing = Customer::factory()->create([
            'name' => 'Ibrahim Waheed',
            'phone' => '7712345',
            'email' => 'corrected@example.mv',
        ]);

        $plan = $this->import()->plan($this->csv('Ibrahim Waheed,old@example.mv,7712345,,,'));

        $this->assertSame(CustomerImport::MATCHED, $plan[0]['outcome']);
        $this->assertStringContainsString('disagrees', $plan[0]['because']);
        $this->assertStringContainsString('old@example.mv', $plan[0]['because']);

        $this->import()->apply($plan);

        $this->assertSame('corrected@example.mv', $existing->fresh()->email);
    }

    /** Case and spacing are not a disagreement. */
    public function test_the_same_name_spaced_differently_is_not_a_disagreement(): void
    {
        Customer::factory()->create(['name' => 'Ibrahim  Waheed', 'phone' => '7712345']);

        $plan = $this->import()->plan($this->csv('ibrahim waheed,,7712345,,,'));

        $this->assertStringContainsString('everything agrees', $plan[0]['because']);
    }

    /**
     * Two customers already share the number — which happens, because two
     * members of staff created the same person twice. The import must not
     * pick one.
     */
    public function test_two_existing_matches_need_a_person(): void
    {
        Customer::factory()->create(['name' => 'Ibrahim Waheed', 'phone' => '7712345']);
        Customer::factory()->create(['name' => 'Ibrahim W.', 'phone' => '+9607712345']);

        $plan = $this->import()->plan($this->csv('Ibrahim Waheed,,7712345,,,'));

        $this->assertSame(CustomerImport::AMBIGUOUS, $plan[0]['outcome']);
        $this->assertStringContainsString('already on 2 customers', $plan[0]['because']);
    }

    /** A spreadsheet kept for six years contains the same person twice. */
    public function test_a_duplicate_inside_the_file_is_caught(): void
    {
        $plan = $this->import()->plan($this->csv(
            "Ibrahim Waheed,,7712345,,,\nIbrahim Waheed,,+960 771 2345,,,",
        ));

        $this->assertSame(CustomerImport::NEW, $plan[0]['outcome']);
        $this->assertSame(CustomerImport::AMBIGUOUS, $plan[1]['outcome']);
        $this->assertStringContainsString('already on line 2 of this file', $plan[1]['because']);
    }

    /**
     * A row with no number cannot be matched on anything reliable, so it is
     * put in front of a person rather than created. Created silently, these
     * fill the table with customers nobody can ever use.
     */
    public function test_a_row_with_no_phone_needs_a_person(): void
    {
        $plan = $this->import()->plan($this->csv('Ibrahim Waheed,ibrahim@example.mv,,,,'));

        $this->assertSame(CustomerImport::AMBIGUOUS, $plan[0]['outcome']);
        $this->assertStringContainsString('No phone number', $plan[0]['because']);
    }

    public function test_a_row_with_no_name_is_skipped(): void
    {
        $plan = $this->import()->plan($this->csv(',,7712345,,,'));

        $this->assertSame(CustomerImport::SKIPPED, $plan[0]['outcome']);
    }

    // ── Applying ─────────────────────────────────────────────────────────

    /** Planning reads. Only applying writes. */
    public function test_planning_writes_nothing(): void
    {
        $this->import()->plan($this->csv("Ibrahim Waheed,,7712345,,,\nAminath Zahira,,7798765,,,"));

        $this->assertSame(0, Customer::count());
    }

    public function test_applying_creates_only_the_new_ones(): void
    {
        Customer::factory()->create(['name' => 'Ibrahim Waheed', 'phone' => '7712345']);

        $plan = $this->import()->plan($this->csv(
            "Ibrahim Waheed,,7712345,,,\n".        // matched
            "Aminath Zahira,,7798765,,,\n".        // new
            ",,7700000,,,\n".                      // skipped, no name
            'Hawwa Latheefa,,,,,',                 // ambiguous, no phone
        ));

        $created = $this->import()->apply($plan);

        $this->assertSame(1, $created);
        $this->assertSame(2, Customer::count());
        $this->assertNotNull(Customer::where('name', 'Aminath Zahira')->first());
        $this->assertNull(Customer::where('name', 'Hawwa Latheefa')->first());
    }

    /** Where a record came from, kept on the record. */
    public function test_an_imported_customer_says_where_it_came_from(): void
    {
        $plan = $this->import()->plan($this->csv('Aminath Zahira,,7798765,,,Paid in cash'));

        $this->import()->apply($plan);

        $notes = (string) Customer::sole()->notes;

        $this->assertStringContainsString('Paid in cash', $notes);
        $this->assertStringContainsString('Imported from', $notes);
    }

    // ── The file itself ──────────────────────────────────────────────────

    /** Six years of edits leave columns nobody remembers adding. */
    public function test_unknown_columns_are_ignored_rather_than_fatal(): void
    {
        $path = $this->csv(
            'Ibrahim Waheed,7712345,who knows',
            'name,phone,legacy_agent_code',
        );

        $plan = $this->import()->plan($path);

        $this->assertSame(CustomerImport::NEW, $plan[0]['outcome']);
        $this->assertSame('Ibrahim Waheed', $plan[0]['row']['name']);
    }

    /** Header names as four different people typed them. */
    public function test_header_names_are_matched_loosely(): void
    {
        $path = $this->csv('Ibrahim Waheed,7712345,A123456', 'Name, Phone ,National ID');

        $plan = $this->import()->plan($path);

        $this->assertSame('Ibrahim Waheed', $plan[0]['row']['name']);
        $this->assertSame('7712345', $plan[0]['row']['phone']);
        $this->assertSame('A123456', $plan[0]['row']['national_id']);
    }

    public function test_an_empty_file_is_not_a_crash(): void
    {
        $this->assertSame([], $this->import()->plan($this->csv('')));
    }

    // ── The command ──────────────────────────────────────────────────────

    /** The default has to be the safe one. */
    public function test_the_command_writes_nothing_without_the_flag(): void
    {
        $path = $this->csv('Ibrahim Waheed,,7712345,,,');

        $this->artisan('customers:import', ['file' => $path])
            ->expectsOutputToContain('Dry run. Nothing was written.')
            ->assertSuccessful();

        $this->assertSame(0, Customer::count());
    }

    public function test_the_command_writes_with_the_flag(): void
    {
        $path = $this->csv('Ibrahim Waheed,,7712345,,,');

        $this->artisan('customers:import', ['file' => $path, '--write' => true])
            ->expectsOutputToContain('1 customers created')
            ->assertSuccessful();

        $this->assertSame(1, Customer::count());
    }

    public function test_a_missing_file_fails_rather_than_importing_nothing_quietly(): void
    {
        $this->artisan('customers:import', ['file' => '/no/such/file.csv'])->assertFailed();
    }

    /** Anything needing a decision is printed in full, not counted. */
    public function test_rows_needing_a_person_are_named(): void
    {
        $path = $this->csv("Ibrahim Waheed,,,,,\nAminath Zahira,,7798765,,,");

        // One assertion, not two: expectsOutputToContain matches each
        // expectation against a *different* line, and both of these are on
        // the same one — so the two-call version passed on the first and
        // reported the second as missing from output that plainly had it.
        $this->artisan('customers:import', ['file' => $path])
            ->expectsOutputToContain('Ibrahim Waheed: No phone number to match on.')
            ->assertSuccessful();
    }
}
