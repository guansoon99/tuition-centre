<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Direct-to-R2 uploads land in the bucket before this server is told
        // about them, so a browser that dies in between leaves an object no
        // row references. Nothing else will ever notice those — see
        // SweepOrphanedSubmissionFiles. Needs a cron entry for schedule:run.
        $schedule->command('submissions:sweep-orphans')->dailyAt('03:30');

        // Database + local uploads to R2. Off this machine on purpose: a
        // backup on the droplet dies with the droplet, which is the failure
        // it is supposed to survive.
        $schedule->command('backup:run')->dailyAt('02:30')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
