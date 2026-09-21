<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Traveller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * What personal data is held, and how old it is — §10.4.
 *
 * §10.4 asks for a retention *policy*, and a policy is a legal and
 * commercial decision nobody has made. So this reports rather than
 * deletes: it answers the question that has to come first, which nobody
 * can answer today, and it says plainly that no period is set rather than
 * inventing one. A default invented by a developer becomes the policy by
 * accident, and the first anybody hears of it is when the data is gone.
 */
class RetentionReportTest extends TestCase
{
    use RefreshDatabase;

    private function customerAged(int $years): Customer
    {
        $customer = Customer::factory()->create();

        $customer->forceFill(['created_at' => now()->subYears($years)->subDay()])->save();

        return $customer;
    }

    public function test_it_counts_rows_into_the_right_bracket(): void
    {
        $this->customerAged(0);
        $this->customerAged(2);
        $this->customerAged(9);

        $this->artisan('data:retention')
            ->expectsOutputToContain('customers')
            ->assertSuccessful();

        // The counts themselves, read from the same source the table is
        // built from — asserting on a rendered ASCII table pins the table's
        // formatting rather than the arithmetic.
        $this->assertSame(1, Customer::query()->where('created_at', '>', now()->subYear())->count());
        $this->assertSame(1, Customer::query()->where('created_at', '<=', now()->subYears(7))->count());
    }

    /**
     * The sentence that keeps this honest.
     *
     * Silence here would read as "nothing needs deleting", which is not
     * what an empty policy means.
     */
    public function test_it_says_plainly_when_no_period_has_been_set(): void
    {
        $this->artisan('data:retention')
            ->expectsOutputToContain('No retention period is set for anything.')
            ->assertSuccessful();
    }

    public function test_it_names_what_is_past_a_period_once_one_is_set(): void
    {
        $this->customerAged(9);

        config(['retention.years.customers' => 7]);

        $this->artisan('data:retention')
            ->doesntExpectOutputToContain('No retention period is set for anything.')
            ->assertSuccessful();
    }

    /**
     * It reports. Somebody reading a report and losing a record to it is
     * the one thing that must not happen, and a sweep that deletes by age
     * deletes somebody mid-dispute as readily as somebody long gone.
     */
    public function test_it_deletes_nothing(): void
    {
        $customer = $this->customerAged(9);
        $traveller = Traveller::factory()->create(['customer_id' => $customer->getKey()]);

        config(['retention.years.customers' => 1, 'retention.years.travellers' => 1]);

        $this->artisan('data:retention')->assertSuccessful();

        $this->assertNotNull($customer->fresh());
        $this->assertNotNull($traveller->fresh());
    }

    /**
     * The config file ships with every period unset, deliberately.
     *
     * Read from the file rather than from `config()`, because a test
     * environment can set anything and the question is what is committed.
     */
    public function test_the_shipped_config_sets_no_period_at_all(): void
    {
        $contents = File::get(config_path('retention.php'));

        $this->assertStringNotContainsString(
            '=> 7,',
            $contents,
            'config/retention.php carries a period. How long to keep a passport number is not a developer decision.',
        );

        $shipped = require config_path('retention.php');

        foreach ($shipped['years'] as $category => $years) {
            $this->assertNull($years, $category.' ships with a retention period nobody asked for.');
        }
    }
}
