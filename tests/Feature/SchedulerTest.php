<?php

namespace Tests\Feature;

use App\Console\Commands\Preflight;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The schedule — §16.12.
 *
 * `stays:expire-holds`, `bookings:expire-holds` and `notices:sweep` existed
 * and nothing ran them: no entry in the schedule, and no cron line on the
 * server to drive one. A lapsed hold then kept its room off the calendar
 * until somebody asked for that exact room. These tests hold the schedule
 * to what the plan says, and hold preflight to telling somebody when the
 * cron line is missing.
 */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, Event> keyed by the command it runs */
    private function events(): array
    {
        $events = [];

        foreach (app(Schedule::class)->events() as $event) {
            $key = $event->command !== null
                ? trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', $event->command))
                : (string) $event->description;

            $events[$key] = $event;
        }

        return $events;
    }

    public function test_the_hold_expiry_and_the_notice_sweep_are_scheduled(): void
    {
        $events = $this->events();

        $this->assertArrayHasKey('stays:expire-holds', $events);
        $this->assertArrayHasKey('bookings:expire-holds', $events);
        $this->assertArrayHasKey('notices:sweep', $events);

        $this->assertSame('*/10 * * * *', $events['stays:expire-holds']->expression);
        $this->assertSame('*/10 * * * *', $events['bookings:expire-holds']->expression);
        $this->assertSame('0 * * * *', $events['notices:sweep']->expression);
    }

    /**
     * A run killed by the host must not hold the next one off for a day,
     * which is the default lifetime of the overlap lock.
     */
    public function test_an_overlap_lock_expires_within_minutes(): void
    {
        foreach (['stays:expire-holds', 'bookings:expire-holds', 'notices:sweep'] as $command) {
            $event = $this->events()[$command];

            $this->assertTrue($event->withoutOverlapping, "{$command} can overlap itself.");
            $this->assertLessThanOrEqual(10, $event->expiresAt, "{$command}'s lock outlives ten minutes.");
        }
    }

    public function test_a_scheduler_run_records_that_it_ran(): void
    {
        $this->travelTo(now()->setTime(12, 1));

        $this->assertNull(Cache::get(Preflight::SCHEDULE_HEARTBEAT));

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertSame(now()->getTimestamp(), Cache::get(Preflight::SCHEDULE_HEARTBEAT));
    }

    // ── Preflight ─────────────────────────────────────────────────────────

    private function productionConfig(): void
    {
        config(['app.debug' => false, 'app.env' => 'production', 'app.url' => 'https://rihla.mv']);
        Setting::setSocialSettings(['whatsapp_number' => '9607972434']);
    }

    public function test_preflight_warns_when_the_scheduler_has_never_run(): void
    {
        $this->productionConfig();

        $this->artisan('rihla:preflight', ['--production' => true])
            ->expectsOutputToContain('schedule:run has never run')
            // A warning: the cron line is set up after the first deploy of
            // this check, and refusing that deploy would refuse the fix.
            ->assertSuccessful();
    }

    public function test_preflight_warns_when_the_scheduler_has_stopped(): void
    {
        $this->productionConfig();
        Cache::forever(Preflight::SCHEDULE_HEARTBEAT, now()->subMinutes(40)->getTimestamp());

        $this->artisan('rihla:preflight', ['--production' => true])
            ->expectsOutputToContain('schedule:run last ran 40 minutes ago')
            ->assertSuccessful();
    }

    public function test_preflight_is_quiet_about_a_running_scheduler(): void
    {
        $this->productionConfig();
        Cache::forever(Preflight::SCHEDULE_HEARTBEAT, now()->subMinute()->getTimestamp());

        $this->artisan('rihla:preflight', ['--production' => true])
            ->doesntExpectOutputToContain('scheduler')
            ->assertSuccessful();
    }

    // ── The cron installer ────────────────────────────────────────────────

    public function test_the_installer_runs_the_scheduler_for_the_directory_it_is_in(): void
    {
        $script = File::get(base_path('scripts/install-scheduler-cron.sh'));

        $this->assertStringContainsString('artisan schedule:run', $script);
        $this->assertStringContainsString('ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"', $script);
        // Replaces only its own line: production's and test's must be able
        // to live in one crontab, beside the test auto-deploy line.
        $this->assertStringContainsString('grep -vF "cd $ROOT && "', $script);
        $this->assertTrue(is_executable(base_path('scripts/install-scheduler-cron.sh')));
    }
}
