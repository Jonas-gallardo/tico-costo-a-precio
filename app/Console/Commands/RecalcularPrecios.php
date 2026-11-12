<?php

namespace App\Console\Commands;

use App\Services\RecalcularPreciosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecalcularPrecios extends Command
{
    /**
     * @var string
     */
    protected $signature = 'precios:recalcular
                            {--dry-run : Solo consulta y muestra los costos pendientes, sin escribir en saArtPrecio}
                            {--tipo-doc= : Tipo de documento del histórico (PROV, PROC, REPO...). Por defecto PROFIT_TIPO_DOC}
                            {--co-precio= : Filtra por código de precio (01, 02, 03, 04)}
                            {--limit= : Máximo de registros a consultar}
                            {--scan-mode : Usa modo escaneo (anti-join) en lugar de modo alerta (trigger). Es el modo del botón web y del scheduler}';

    /**
     * @var string
     */
    protected $description = 'Calcula y registra en saArtPrecio el precio vigente de los costos de entrada pendientes';

    public function handle(): int
    {
        $tipoDoc = strtoupper(trim((string) ($this->option('tipo-doc') ?: config('profit.tipo_doc_default', 'PROV'))));
        $coPrecio = $this->option('co-precio') !== null ? trim((string) $this->option('co-precio')) : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $modoEscaneo = (bool) $this->option('scan-mode');

        $permitidos = (array) config('profit.tipos_doc_entrada', []);
        if ($permitidos !== [] && ! in_array($tipoDoc, $permitidos, true)) {
            $this->warn("El tipo_doc '{$tipoDoc}' no está en PROFIT_TIPOS_DOC_ENTRADA (".implode(', ', $permitidos).'). Se usará igualmente.');
        }

        if (! $dryRun && ! (bool) config('profit.escritura_habilitada', false)) {
            $this->warn('La escritura está deshabilitada (PROFIT_ESCRITURA_HABILITADA=false). Se fuerza --dry-run.');
            $dryRun = true;
        }

        try {
            $service = new RecalcularPreciosService(
                connection: null,
                tipoDoc: $tipoDoc,
                modoEscaneo: $modoEscaneo,
            );

            $resultado = $service->procesar($dryRun, $tipoDoc, $coPrecio, $limit);

            $registros = $resultado['registros'] ?? collect();

            if ($registros->isEmpty()) {
                $this->info('No hay costos pendientes para los filtros indicados.');
            } else {
                $filas = $registros->map(fn (object $r): array => [
                    trim((string) $r->co_art),
                    (string) $r->art_des,
                    trim((string) $r->co_lin),
                    trim((string) $r->cod_almacen),
                    trim((string) $r->tipo_doc),
                    number_format((float) $r->costo, 5, '.', ''),
                    trim((string) $r->co_precio),
                    number_format((float) $r->monto_min, 2, '.', ''),
                    number_format((float) $r->monto_max, 2, '.', ''),
                    number_format((float) ($r->precio_calculado ?? 0), 2, '.', ''),
                    $r->fecha_emision ? substr((string) $r->fecha_emision, 0, 10) : '',
                ])->all();

                $this->table(
                    ['Artículo', 'Descripción', 'Línea', 'Almacén', 'TipoDoc', 'Costo', 'Precio', 'Mín %', 'Máx %', 'Final', 'Fecha'],
                    $filas
                );
            }

            $this->newLine();
            $this->line('Modo: '.($resultado['dry_run'] ? '<fg=yellow>DRY-RUN (sin escritura)</>' : '<fg=red>ESCRITURA</>'));
            $this->line('Modo de detección: '.($resultado['modo'] === 'escaneo' ? '<fg=cyan>ESCANEO (anti-join)</>' : '<fg=green>ALERTA (trigger)</>'));
            $this->line('Tipo de documento: '.$resultado['tipo_doc']);
            $this->line('Conexión: '.$service->connection());
            $this->line('Registros pendientes: '.$resultado['total_pendientes']);
            $this->line('Insertados: '.$resultado['insertados']);
            $this->line('Vigencias cerradas (hasta = NOW()): '.$resultado['cerrados']);
            $this->line('Alertas marcadas como procesadas: '.$resultado['alertas_procesadas']);
            $this->line('Duración: '.$resultado['duracion_ms'].' ms');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('recalcular-precios: error', [
                'mensaje' => $e->getMessage(),
                'tipo_doc' => $tipoDoc,
                'co_precio' => $coPrecio,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error('Error al recalcular precios: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
