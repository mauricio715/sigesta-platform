<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas de SI-GESTA
|--------------------------------------------------------------------------
| Desarrollo:  php artisan schedule:work
| Servidor:    cron =>  * * * * * cd /ruta/backend && php artisan schedule:run
*/

// Motor de alertas de vencimiento (ventana preventiva dinámica)
Schedule::command('sigesta:evaluar-alertas')
    ->dailyAt('00:05')
    ->withoutOverlapping();

// Limpia de la BD los tokens expirados hace más de 24 horas
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:00');
