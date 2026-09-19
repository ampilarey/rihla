<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The tooling that stands between main and the live site.
 *
 * test.rihla.mv auto-deploys on every push and runs `migrate --force`
 * unattended. That is right for test and is exactly what must never happen to
 * production, where the same command runs against real passports, bookings and
 * payments. Until now there was no production path at all — which is why five
 * weeks of work has sat on test.
 */
class DeploymentToolingTest extends TestCase
{
    use RefreshDatabase;

    private function script(): string
    {
        return File::get(base_path('scripts/deploy-production.sh'));
    }

    /**
     * The script without its comments.
     *
     * The comment above the backup step explains that the deploy runs
     * `migrate --force`, and an earlier version of the ordering test below
     * found that sentence rather than the command — reporting that the
     * migration came first. Comments have now been mistaken for code five
     * times in this branch, in Blade, in CSS and here.
     */
    private function scriptWithoutComments(): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', $this->script());
    }

    /**
     * Backed by a real file on its own connection, not the suite's in-memory
     * one. RefreshDatabase holds a transaction open for the length of each
     * test, and SQLite will not VACUUM from inside one — which is correct, and
     * is what the command now reports rather than producing a file nobody can
     * trust.
     */
    private function fileConnection(): string
    {
        $path = storage_path('framework/testing/backup-source-'.uniqid().'.sqlite');
        File::put($path, '');

        config(['database.connections.backup_test' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);

        $this->artisan('migrate', ['--database' => 'backup_test', '--force' => true]);

        return 'backup_test';
    }

    public function test_the_backup_command_writes_a_file_it_has_verified(): void
    {
        $directory = storage_path('framework/testing/backups-'.uniqid());

        $this->artisan('rihla:backup', ['--path' => $directory, '--connection' => $this->fileConnection()])
            ->assertSuccessful();

        $files = File::files($directory);

        $this->assertCount(1, $files);
        $this->assertGreaterThan(1024, File::size($files[0]->getPathname()),
            'A dump that exists but is empty looks like a safety net while being a hole.');

        File::deleteDirectory($directory);
    }

    /** Old dumps must not be the thing that fills the disk. */
    public function test_the_backup_command_prunes_old_dumps(): void
    {
        $directory = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($directory);

        foreach (range(1, 5) as $i) {
            File::put($directory."/rihla-old-{$i}.sqlite", str_repeat('x', 2048));
            touch($directory."/rihla-old-{$i}.sqlite", now()->subDays($i)->timestamp);
        }

        $this->artisan('rihla:backup', [
            '--path' => $directory, '--keep' => 3, '--connection' => $this->fileConnection(),
        ])->assertSuccessful();

        $this->assertCount(3, File::files($directory));

        File::deleteDirectory($directory);
    }

    /** A stack trace on the live site hands out configuration and file paths. */
    public function test_preflight_refuses_a_production_deploy_with_debug_on(): void
    {
        config(['app.debug' => true, 'app.env' => 'production', 'app.url' => 'https://rihla.mv']);

        $this->artisan('rihla:preflight', ['--production' => true])
            ->expectsOutputToContain('APP_DEBUG')
            ->assertFailed();
    }

    /**
     * The seeders refuse to run in production, but a database restored from
     * test carries the demo content in anyway.
     */
    public function test_preflight_refuses_a_production_deploy_carrying_demo_content(): void
    {
        config(['app.debug' => false, 'app.env' => 'production', 'app.url' => 'https://rihla.mv']);

        Trip::create([
            'title' => 'Luxury Resort Experience', 'slug' => 'luxury-resort-experience',
            'date_start' => now(), 'date_end' => now()->addDay(), 'status' => 'upcoming', 'is_published' => true,
        ]);

        $this->artisan('rihla:preflight', ['--production' => true])
            ->expectsOutputToContain('demo content')
            ->assertFailed();
    }

    public function test_preflight_passes_a_healthy_production_configuration(): void
    {
        config(['app.debug' => false, 'app.env' => 'production', 'app.url' => 'https://rihla.mv']);

        Setting::setSocialSettings(['whatsapp_number' => '9607972434']);

        $this->artisan('rihla:preflight', ['--production' => true])->assertSuccessful();
    }

    /**
     * Demo content reached production once and advertised resort holidays on
     * an Umrah site. The deploy script must not be able to do that again, not
     * even behind a flag.
     */
    public function test_the_production_script_can_never_seed(): void
    {
        $this->assertStringNotContainsString('db:seed', $this->script());
        $this->assertStringNotContainsString('--seed', $this->script());
    }

    /** A snapshot taken mid-transaction is a snapshot of nothing. */
    public function test_the_backup_command_refuses_to_run_inside_a_transaction(): void
    {
        // RefreshDatabase already holds one open.
        $this->artisan('rihla:backup', ['--path' => storage_path('framework/testing/never')])
            ->expectsOutputToContain('transaction')
            ->assertFailed();
    }

    /** A backup that is skipped is not a backup. */
    public function test_the_production_script_backs_up_before_it_migrates(): void
    {
        $script = $this->scriptWithoutComments();

        $backupAt = strpos($script, 'rihla:backup');
        $migrateAt = strpos($script, 'migrate --force');

        $this->assertNotFalse($backupAt, 'The production script takes no backup.');
        $this->assertNotFalse($migrateAt);
        $this->assertLessThan($migrateAt, $backupAt, 'The backup must come before the migration.');

        // And it must stop when the backup fails.
        $this->assertMatchesRegularExpression('/rihla:backup.*\|\|\s*die/', $script);
    }

    /** Production is promoted on purpose, never by a webhook. */
    public function test_nothing_triggers_the_production_script_automatically(): void
    {
        foreach (File::files(base_path('.github/workflows')) as $workflow) {
            $this->assertStringNotContainsString('deploy-production', File::get($workflow->getPathname()),
                "{$workflow->getFilename()} would deploy production automatically.");
        }
    }

    /** Refusing to move production backwards is the whole point of the check. */
    public function test_the_production_script_refuses_a_commit_that_is_not_a_descendant(): void
    {
        $this->assertStringContainsString('merge-base --is-ancestor', $this->script());
        $this->assertStringContainsString('--ff-only', $this->script());
    }
}
