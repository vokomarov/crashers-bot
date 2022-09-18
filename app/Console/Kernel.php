<?php

namespace App\Console;

use App\Console\Commands\PidarChatCheck;
use App\Console\Commands\PidarGift;
use App\Console\Commands\PidarReportsCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command(PidarGift::class)->everyMinute();
        $schedule->command(PidarChatCheck::class)->dailyAt('09:00');
        $schedule->command(PidarReportsCheck::class)->dailyAt('21:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
