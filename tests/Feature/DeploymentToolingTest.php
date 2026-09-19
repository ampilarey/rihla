<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * A backup nobody has restored is a hope, not a backup.
     *
     * `docs/PRODUCTION_PROMOTION.md` said exactly that and then left it as
     * homework. These are the parts of that homework a machine can do.
     */
    public function test_a_good_backup_passes_verification(): void
    {
        $directory = storage_path('framework/testing/backups-'.uniqid());
        $connection = $this->fileConnection();

        $this->artisan('rihla:backup', ['--path' => $directory, '--connection' => $connection])
            ->assertSuccessful();

        $this->artisan('rihla:backup:verify', ['--path' => $directory, '--connection' => $connection])
            ->expectsOutputToContain('integrity_check: ok')
            ->assertSuccessful();

        File::deleteDirectory($directory);
    }

    /**
     * The failure this exists to catch. A dump cut off by a full disk keeps a
     * plausible size and a plausible name; the damage only shows when you open
     * it, which is the day you least want a surprise.
     */
    public function test_a_truncated_backup_fails_verification(): void
    {
        $directory = storage_path('framework/testing/backups-'.uniqid());
        $connection = $this->fileConnection();

        $this->artisan('rihla:backup', ['--path' => $directory, '--connection' => $connection])
            ->assertSuccessful();

        $backup = File::files($directory)[0]->getPathname();
        $whole = File::size($backup);
        File::put($backup, substr((string) File::get($backup), 0, (int) ($whole / 4)));

        $this->artisan('rihla:backup:verify', ['--path' => $directory, '--connection' => $connection])
            ->assertFailed();

        File::deleteDirectory($directory);
    }

    /**
     * A backup can open cleanly and still be useless: SQLite is perfectly
     * happy with a file that is missing the table holding the bookings.
     */
    public function test_a_backup_missing_a_table_fails_verification(): void
    {
        $directory = storage_path('framework/testing/backups-'.uniqid());
        $connection = $this->fileConnection();

        $this->artisan('rihla:backup', ['--path' => $directory, '--connection' => $connection])
            ->assertSuccessful();

        $backup = File::files($directory)[0]->getPathname();

        config(['database.connections.backup_damage' => [
            'driver' => 'sqlite', 'database' => $backup, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::connection('backup_damage')->statement('DROP TABLE trips');
        DB::purge('backup_damage');

        $this->artisan('rihla:backup:verify', ['--path' => $directory, '--connection' => $connection])
            ->expectsOutputToContain('trips')
            ->assertFailed();

        File::deleteDirectory($directory);
    }

    /**
     * mysqldump writes its completion marker last, so a dump without one
     * stopped early — however whole the gzip around it is.
     */
    public function test_a_mysql_dump_without_a_completion_marker_fails_verification(): void
    {
        $directory = storage_path('framework/testing/backups-'.uniqid());
        File::ensureDirectoryExists($directory);

        $tables = collect(DB::connection($connection = $this->fileConnection())
            ->getSchemaBuilder()->getTableListing(schemaQualified: false))
            ->map(fn ($table) => "CREATE TABLE `{$table}` (`id` int);")
            ->implode("\n");

        File::put($directory.'/rihla-cut.sql.gz', (string) gzencode("-- MySQL dump 10.13\n".$tables."\n"));

        $this->artisan('rihla:backup:verify', ['--path' => $directory, '--connection' => $connection])
            ->expectsOutputToContain('stopped early')
            ->assertFailed();

        File::deleteDirectory($directory);
    }

    /** Nothing to check is a failure, not a pass. */
    public function test_verification_fails_when_there_is_no_backup_at_all(): void
    {
        $this->artisan('rihla:backup:verify', ['--path' => storage_path('framework/testing/nothing-here')])
            ->expectsOutputToContain('No backup found')
            ->assertFailed();
    }

    /**
     * The deploy must check its own backup, not merely take one.
     *
     * Verifying afterwards is no use: by then the migration has run and the
     * question is whether there is a way back.
     */
    public function test_the_production_script_verifies_the_backup_before_it_migrates(): void
    {
        $script = $this->scriptWithoutComments();

        $backupAt = strpos($script, 'rihla:backup ');
        $verifyAt = strpos($script, 'rihla:backup:verify');
        $migrateAt = strpos($script, 'migrate --force');

        $this->assertNotFalse($verifyAt, 'The production script never checks the backup it just took.');
        $this->assertLessThan($verifyAt, $backupAt);
        $this->assertLessThan($migrateAt, $verifyAt, 'A backup verified after the migration answers nothing.');

        $this->assertMatchesRegularExpression('/rihla:backup:verify.*\|\|\s*die/', $script,
            'A failed verification must stop the deploy.');
    }

    /** The promotion doc must not still list a tested restore as simply missing. */
    public function test_the_promotion_doc_no_longer_lists_a_tested_restore_as_missing(): void
    {
        $doc = File::get(base_path('docs/PRODUCTION_PROMOTION.md'));

        $this->assertStringContainsString('rihla:backup:verify', $doc,
            'The promotion checklist must tell the operator to verify the backup it just took.');
    }

    /** Refusing to move production backwards is the whole point of the check. */
    public function test_the_production_script_refuses_a_commit_that_is_not_a_descendant(): void
    {
        $this->assertStringContainsString('merge-base --is-ancestor', $this->script());
        $this->assertStringContainsString('--ff-only', $this->script());
    }

    /**
     * The script deploys itself, so it must not be read from the file it is
     * about to rewrite.
     *
     * Step 4 runs `git merge --ff-only`, and any release that changes
     * scripts/deploy-production.sh rewrites the file bash is executing. Bash
     * reads a script lazily, by byte offset. Demonstrated rather than assumed:
     * a script that rewrites itself mid-run prints its first line, then
     * silently stops and **exits 0**. On a deploy that means merging the code
     * and then skipping composer install, the migration, the caches and
     * `php artisan up` — leaving the site in maintenance mode with unmigrated
     * code, while reporting success.
     *
     * Re-running from a temp copy costs nothing and removes the whole class.
     */
    public function test_the_production_script_runs_from_a_snapshot_of_itself(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('RIHLA_DEPLOY_SNAPSHOT', $script,
            'The script rewrites itself at the merge step and must not be read from that file.');

        $this->assertMatchesRegularExpression('/mktemp.*\n.*cp "\$0"/m', $script,
            'The snapshot must be a copy of the running script.');

        // And the guard has to come before the merge, or it guards nothing.
        $guardAt = strpos($script, 'RIHLA_DEPLOY_SNAPSHOT');
        $mergeAt = strpos($this->scriptWithoutComments(), 'merge --ff-only');

        $this->assertNotFalse($guardAt);
        $this->assertNotFalse($mergeAt);
        $this->assertLessThan($mergeAt, $guardAt);
    }
}
