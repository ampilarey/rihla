<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Trip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Refuse a production deploy for the reasons a backup cannot cover.
 *
 * Everything here is something that has actually gone wrong on this project
 * or is one careless `.env` away from doing so. Each check answers a question
 * someone would otherwise ask after the fact.
 */
class Preflight extends Command
{
    protected $signature = 'rihla:preflight {--production : Apply the checks that only matter on the live site}';

    protected $description = 'Check this installation is fit to serve before deploying';

    /** @var list<array{string, string}> */
    private array $failures = [];

    /** @var list<array{string, string}> */
    private array $warnings = [];

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();

        $this->checkDatabase();
        $this->checkBuild();
        $this->checkStorage();

        if ($production) {
            $this->checkProductionEnvironment();
            $this->checkNoDemoContent();
        }

        foreach ($this->warnings as [$name, $detail]) {
            $this->line("  <fg=yellow>warn</>  {$name} — {$detail}");
        }

        foreach ($this->failures as [$name, $detail]) {
            $this->line("  <fg=red>fail</>  {$name} — {$detail}");
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->error(count($this->failures).' check(s) failed. Not fit to deploy.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Preflight passed'.($this->warnings !== [] ? ' with '.count($this->warnings).' warning(s).' : '.'));

        return self::SUCCESS;
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->addFailure('database', 'cannot connect: '.$e->getMessage());

            return;
        }

        $pending = collect(app('migrator')->getMigrationFiles(database_path('migrations')))
            ->keys()
            ->diff(app('migrator')->getRepository()->getRan())
            ->count();

        if ($pending > 0) {
            $this->addWarning('migrations', "{$pending} pending — the deploy will run them, so take the backup first");
        }
    }

    private function checkBuild(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! File::exists($manifest)) {
            $this->addFailure('vite build', 'public/build/manifest.json is missing; every page will throw');

            return;
        }

        // The server has no Node, so the build is committed. A manifest naming
        // a file that was never committed is the failure mode.
        foreach (json_decode(File::get($manifest), true) ?: [] as $entry) {
            if (isset($entry['file']) && ! File::exists(public_path('build/'.$entry['file']))) {
                $this->addFailure('vite build', "manifest names {$entry['file']}, which is not in public/build");
            }
        }
    }

    private function checkStorage(): void
    {
        if (! File::isWritable(storage_path('framework/views'))) {
            $this->addFailure('storage', 'storage/framework/views is not writable');
        }

        if (! File::exists(public_path('storage'))) {
            $this->addWarning('storage', 'public/storage symlink is missing; uploaded images will 404');
        }
    }

    private function checkProductionEnvironment(): void
    {
        if (config('app.debug')) {
            $this->addFailure('APP_DEBUG', 'is true; a stack trace on the live site exposes configuration and file paths');
        }

        if (config('app.env') !== 'production') {
            $this->addFailure('APP_ENV', 'is ['.config('app.env').']; demo seeders only refuse to run when it is production');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->addFailure('APP_URL', 'is not https; HSTS and secure cookies depend on it');
        }

        if (config('app.key') === '' || config('app.key') === null) {
            $this->addFailure('APP_KEY', 'is empty; sessions and encrypted columns cannot be read');
        }

        if (Setting::getWhatsAppNumber() === '') {
            $this->addFailure('settings', 'no WhatsApp number is configured; every contact link is dead');
        }
    }

    /**
     * Demo content reached production once and advertised resort holidays on
     * an Umrah site. The seeders refuse to run there now, but a database
     * restored from test would carry it in anyway.
     */
    private function checkNoDemoContent(): void
    {
        $slugs = ['maldives-island-hopping-adventure', 'luxury-resort-experience', 'cultural-heritage-tour'];

        $found = Trip::whereIn('slug', $slugs)->pluck('slug');

        if ($found->isNotEmpty()) {
            $this->addFailure('demo content', 'these demo trips are in the database: '.$found->implode(', '));
        }

        if (Setting::getSocialSettings()['youtube_playlist_id'] === 'PLxxxxxxxxxx') {
            $this->addFailure('demo content', 'the YouTube playlist is still the PLxxxxxxxxxx placeholder');
        }
    }

    /** Named addFailure, not fail: Command::fail() already exists and throws. */
    private function addFailure(string $name, string $detail): void
    {
        $this->failures[] = [$name, $detail];
    }

    private function addWarning(string $name, string $detail): void
    {
        $this->warnings[] = [$name, $detail];
    }
}
