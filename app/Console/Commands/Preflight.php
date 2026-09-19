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

    /** Big enough to be worth saying, small enough to still be fixable. */
    private const LOG_SIZE_WARNING = 100 * 1024 * 1024;

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
            $this->checkLogging();
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
     * The log is the only record of what went wrong on a host nobody watches.
     *
     * Two ways it stops being one. An unrotated `single` channel grows until
     * the account hits its disk quota, at which point the site cannot write a
     * session or accept an upload — a failure that looks nothing like a full
     * disk. And `LOG_LEVEL=debug` on a live site buries the one line that
     * matters under every query and cache read, which is the same thing as
     * having no log.
     *
     * Warnings, not failures: a full log is a problem next month, and
     * blocking a deploy over it would teach people to skip preflight.
     */
    private function checkLogging(): void
    {
        $default = (string) config('logging.default');

        // `stack` is a list of other channels; anything else is one channel.
        $channels = $default === 'stack'
            ? array_map('strval', (array) config('logging.channels.stack.channels'))
            : [$default];

        if (in_array('single', $channels, true)) {
            $this->addWarning('logging', 'the single channel never rotates; set LOG_STACK=daily before storage/logs fills the account quota');
        }

        // Read back through config, not env(). Production runs config:cache,
        // and env() returns null there — a check that reads it would report
        // the default on every cached host and never fire.
        foreach ($channels as $channel) {
            $level = (string) config("logging.channels.{$channel}.level", 'debug');

            if (in_array($level, ['debug', 'info'], true)) {
                $this->addWarning('logging', "the {$channel} channel logs at [{$level}]; production wants warning or above, or the line that matters is buried");
            }
        }

        $log = storage_path('logs/laravel.log');

        if (File::exists($log) && File::size($log) > self::LOG_SIZE_WARNING) {
            $this->addWarning('logging', 'storage/logs/laravel.log is '.round(File::size($log) / 1048576).' MB; rotate or delete it');
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

        $social = Setting::getSocialSettings();

        if (($social['youtube_playlist_id'] ?? null) === 'PLxxxxxxxxxx') {
            $this->addFailure('demo content', 'the YouTube playlist is still the PLxxxxxxxxxx placeholder');
        }

        $this->checkSeededSocialLinks($social);
    }

    /**
     * Social URLs nobody has confirmed.
     *
     * The seeder used to invent four of them from one handle. TikTok's was
     * provably dead and has been cleared; Facebook, Instagram and Viber cannot
     * be checked from a script — they answer a login wall or a generic page
     * whether or not the account exists. Only the owner knows.
     *
     * A warning rather than a failure: the link may well be right, and
     * blocking a deploy over a guess that might be correct would teach people
     * to ignore preflight.
     *
     * @param  array<string, mixed>  $social
     */
    private function checkSeededSocialLinks(array $social): void
    {
        $seeded = [
            'facebook_url' => 'https://facebook.com/rihlatravels',
            'instagram_url' => 'https://instagram.com/rihlatravels',
            'tiktok_url' => 'https://tiktok.com/@rihlatravels',
            'viber_url' => 'https://viber.com/rihlatravels',
        ];

        $unconfirmed = [];

        foreach ($seeded as $key => $guess) {
            if (($social[$key] ?? null) === $guess) {
                $unconfirmed[] = $key;
            }
        }

        if ($unconfirmed !== []) {
            $this->addWarning('social links', implode(', ', $unconfirmed)
                .' still hold the seeded guess. Confirm the accounts exist, or clear them in Admin → Settings — the page hides a link that is not set.');
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
