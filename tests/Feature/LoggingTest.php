<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The log is the only record of what went wrong on a host nobody watches.
 *
 * See docs/adr/0005-observability-on-a-host-nobody-watches.md.
 */
class LoggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Laravel's own default is `single`, which appends to one file forever.
     * On shared hosting the account has a disk quota, and the first anyone
     * hears of that file is the site failing to write a session or accept an
     * upload — a failure that looks nothing like a full disk.
     */
    public function test_the_default_stack_rotates(): void
    {
        // The config file re-evaluated with LOG_STACK unset, not the value
        // resolved for this process. A developer's own .env — or CI's — would
        // otherwise decide whether this passes, and the default is the whole
        // point: it governs every host that never set the variable.
        $logging = $this->configWithout('LOG_STACK');

        $this->assertNotContains('single', $logging['channels']['stack']['channels'],
            'The default log stack never rotates; storage/logs will grow until the account quota stops the site.');

        $this->assertContains('daily', $logging['channels']['stack']['channels']);
        $this->assertGreaterThan(0, (int) $logging['channels']['daily']['days']);
    }

    /**
     * Re-read config/logging.php with one environment variable removed, so
     * the value under test is the file's own default.
     *
     * @return array<string, mixed>
     */
    private function configWithout(string $variable): array
    {
        $original = $_ENV[$variable] ?? null;
        $originalServer = $_SERVER[$variable] ?? null;

        unset($_ENV[$variable], $_SERVER[$variable]);
        putenv($variable);

        try {
            return require base_path('config/logging.php');
        } finally {
            if ($original !== null) {
                $_ENV[$variable] = $original;
                putenv($variable.'='.$original);
            }

            if ($originalServer !== null) {
                $_SERVER[$variable] = $originalServer;
            }
        }
    }

    /** The shipped example is what a new host is configured from. */
    public function test_the_env_example_does_not_teach_the_unrotated_channel(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString("\nLOG_STACK=single", $env);
        $this->assertStringContainsString("\nLOG_STACK=daily", $env);
    }

    /**
     * Preflight is what a deploy actually runs, so the warning has to come
     * out of it rather than out of the config being right in the repository.
     * A live `.env` copied from an older example still says `single`.
     */
    public function test_preflight_warns_about_an_unrotated_log_on_production(): void
    {
        config(['logging.channels.stack.channels' => ['single']]);

        $this->artisan('rihla:preflight --production')
            ->expectsOutputToContain('the single channel never rotates');
    }

    public function test_preflight_warns_when_production_logs_at_debug(): void
    {
        config([
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.level' => 'debug',
        ]);

        $this->artisan('rihla:preflight --production')
            ->expectsOutputToContain('production wants warning or above');
    }

    public function test_preflight_is_quiet_when_logging_is_set_up_properly(): void
    {
        config([
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.level' => 'warning',
        ]);

        $this->artisan('rihla:preflight --production')
            ->doesntExpectOutputToContain('never rotates')
            ->doesntExpectOutputToContain('production wants warning or above');
    }
}
