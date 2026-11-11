<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Actualización automática de precios
|--------------------------------------------------------------------------
|
| Ejecuta el motor cada 1 minuto en modo escritura (sin --dry-run).
| Fase 6: Arquitectura híbrida con trigger para respuesta casi en tiempo real.
|
| withoutOverlapping(10): si una corrida tarda más de 1 minuto, la
| siguiente no se dispara. El bloqueo expira a los 10 minutos para que
| un proceso que muera de forma anormal no bloquee el scheduler todo el día.
|
| Los conteos de la corrida los registra el propio servicio en
| storage/logs/laravel.log con el evento "recalcular-precios: fin".
| Los callbacks before/after dejan constancia de que la ejecución fue
| automática (no manual).
|
*/

Schedule::command('precios:recalcular')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->before(function (): void {
        Log::info('scheduler: inicio automático de precios:recalcular');
    })
    ->after(function (): void {
        Log::info('scheduler: fin automático de precios:recalcular');
    });
