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
        // Scheduler padrão: processa a fila a cada minuto e encerra quando estiver vazia
        // Requer cron chamando: php artisan schedule:run a cada 1 minuto (em produção mantemos queue:work via systemd)
        $schedule->command('queue:work', [
            '--queue' => 'imports',
            '--sleep' => 1,
            '--tries' => 1,
            '--stop-when-empty' => true,
        ])->everyMinute()->withoutOverlapping();
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
