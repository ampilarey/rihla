<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeders carrying real site content. Safe in any environment.
     *
     * @var list<class-string<Seeder>>
     */
    private const CONTENT = [
        SettingsSeeder::class,
        UmrahGuideSeeder::class,
        WhySectionSeeder::class,
    ];

    /**
     * Seeders carrying demo content, for local and testing only. Each also
     * guards itself, so running one directly in production is a no-op too.
     *
     * @var list<class-string<Seeder>>
     */
    private const DEMO = [
        TripSeeder::class,
        MediaSeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::CONTENT);

        if (app()->isProduction()) {
            $this->command?->warn('Demo seeders skipped in production.');

            return;
        }

        $this->call(self::DEMO);
    }
}
