<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Regression for the backup-capacity incident: backup:clean was never
 * scheduled anywhere, so nothing ever pruned old archives and 27GB+ of
 * junk accumulated on production. These tests exercise the real
 * Illuminate\Console\Scheduling\Schedule the app builds from
 * routes/console.php via Kernel::schedule(), not a hand-rolled fixture.
 */
class BackupScheduleTest extends TestCase
{
    /** @return array<int, \Illuminate\Console\Scheduling\Event> */
    private function eventsMatching(string $needle): array
    {
        $schedule = $this->app->make(Schedule::class);

        return array_values(array_filter(
            $schedule->events(),
            fn ($event) => str_contains($event->command ?? '', $needle)
        ));
    }

    public function test_backup_run_is_scheduled_exactly_once(): void
    {
        $this->assertCount(1, $this->eventsMatching('backup:run'));
    }

    public function test_backup_clean_is_scheduled_exactly_once(): void
    {
        $this->assertCount(1, $this->eventsMatching('backup:clean'));
    }

    public function test_backup_clean_runs_after_backup_run(): void
    {
        $run = $this->eventsMatching('backup:run')[0];
        $clean = $this->eventsMatching('backup:clean')[0];

        // One minute before midnight so both events' *next* run lands today,
        // rather than nextRunDate() rolling backup:run past to tomorrow
        // (CronExpression excludes an exact-match reference by default).
        $reference = now()->startOfDay()->subMinute();

        $runAt = $run->nextRunDate($reference);
        $cleanAt = $clean->nextRunDate($reference);

        $this->assertTrue(
            $cleanAt->greaterThan($runAt),
            'backup:clean must run after backup:run on the same day so it never inspects a destination backup:run is still writing to.'
        );
    }

    public function test_backup_clean_has_bounded_overlap_protection(): void
    {
        $clean = $this->eventsMatching('backup:clean')[0];

        $this->assertTrue($clean->withoutOverlapping, 'backup:clean must guard against overlapping runs.');
        $this->assertIsInt($clean->expiresAt);
        $this->assertGreaterThan(0, $clean->expiresAt);
        $this->assertLessThanOrEqual(1440, $clean->expiresAt, 'Overlap lock must expire within a day, not linger forever.');
    }

    public function test_backup_schedule_has_no_hardcoded_environment_path(): void
    {
        foreach (['backup:run', 'backup:clean'] as $needle) {
            $event = $this->eventsMatching($needle)[0];

            $this->assertStringNotContainsString('/var/www/jewelflow', $event->command);
            $this->assertStringNotContainsString('jewelflow-staging', $event->command);
        }
    }
}
