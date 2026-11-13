<?php

namespace App\Console\Commands;

use App\Services\RecalcularPreciosService;
use Illuminate\Console\Command;
use Throwable;

class RepararPrecios extends Command
{
    protected $signature = 'precios:reparar
                            {--dry-run : Muestra los precios que se repararían sin escribir en la base}';

    protected $description = 'Cierra y recalcula los precios vigentes que quedaron iguales al costo por margen 0';

    public function handle(RecalcularPreciosService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! (bool) config('profit.escritura_habilitada', false)) {
            $this->warn('La escritura está deshabilitada (PROFIT_ESCRITURA_HABILITADA=false). Use --dry-run o active la escritura.');
            $this->line('No se modificó ningún registro.');

            return self::FAILURE;
        }

        try {
            $resultado = $service->repararPreciosIgualesAlCosto($dryRun);
        } catch (Throwable $e) {
            $this->error('No se pudieron reparar los precios: '.$e->getMessage());

            return self::FAILURE;
        }

        $detalles = $resultado['detalles'];

        if ($detalles === []) {
            $this->info('No se encontraron precios vigentes iguales al costo con margen mayor a 0.');

            return self::SUCCESS;
        }

        $this->table(
            ['Artículo', 'Descripción', 'Lista', 'Costo', 'Margen %', 'Precio actual', 'Precio nuevo'],
            collect($detalles)->map(fn (array $detalle): array => [
                $detalle['co_art'],
                $detalle['art_des'],
                $detalle['co_precio'],
                number_format($detalle['costo'], 5, '.', ''),
                number_format($detalle['monto_min'], 2, '.', ''),
                number_format($detalle['precio_actual'], 5, '.', ''),
                number_format($detalle['precio_nuevo'], 2, '.', ''),
            ])->all()
        );

        $verbo = $dryRun ? 'Se repararían' : 'Se repararon';
        $this->info("{$verbo} {$resultado['reparados']} precios.");
        $this->line($dryRun ? 'Modo: DRY-RUN (sin escritura)' : 'Modo: ESCRITURA');

        return self::SUCCESS;
    }
}
