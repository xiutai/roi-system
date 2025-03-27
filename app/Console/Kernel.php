<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * 应用中的Artisan命令
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\CreateAdminUser::class,
        \App\Console\Commands\ClearAllData::class,
        \App\Console\Commands\CreateDefaultAdmin::class,
        \App\Console\Commands\FixChannelIdMappings::class,
        \App\Console\Commands\DeleteImportJob::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();
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
