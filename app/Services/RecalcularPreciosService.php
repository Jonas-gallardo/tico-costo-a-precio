<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Detección y generación de precios a partir del costo histórico.
 *
 * Fase 2:
 *   - Anti-join para detectar pendientes (sin tocar el esquema de Profit).
 *     Un costo se considera pendiente si no existe un precio vigente
 *     generado después de su fecha de registro, o si es el costo más
 *     reciente del artículo.
 *   - Cálculo directo: precio = costo + (costo * monto_min / 100), 2 decimales.
 *   - Cierre de vigencia anterior (hasta = NOW()) e INSERT del nuevo precio
 *     dentro de la MISMA transacción.
 *
 * Decisiones acordadas con el cliente:
 *   - Se ignora la validación de topes/políticas (NMMI, NMMS, NRA) del SP.
 *   - Se procesan todos los códigos de precio con margen configurado.
 *   - co_alma = NULL  ->  co_alma_calculado (columna calculada) = 'TODOS'.
 *   - NO se inserta en co_alma_calculado.
 */
class RecalcularPreciosService
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly ?string $tipoDoc = null,
    ) {
    }

    public function connection(): string
    {
        return $this->connection ?? (string) config('profit.connection', 'sqlsrv');
    }

    public function tipoDoc(): string
    {
        return $this->tipoDoc ?? (string) config('profit.tipo_doc_default', 'PROV');
    }

    /**
     * Costos de entrada que requieren un precio nuevo.
     *
     * Se considera pendiente cuando no existe un precio vigente (hasta NULL)
     * para el mismo (co_art, co_precio) creado después de la fecha de
     * registro del costo. Además, solo se devuelve el costo más reciente
     * por artículo y tipo de documento.
     *
     * @return Collection<int, object>
     */
    public function costosPendientes(
        ?string $tipoDoc = null,
        ?string $coPrecio = null,
        ?int $limit = null
    ): Collection {
        $tipoDoc = $tipoDoc ?: $this->tipoDoc();

        $query = DB::connection($this->connection())
            ->table('dbo.saCostoHistoricoEntrada as h')
            ->join('dbo.saArticulo as a', 'a.rowguid', '=', 'h.cod_articulo_rowguid')
            ->join('dbo.saArtMargen as m', 'm.co_art', '=', 'a.co_art')
            ->select([
                'h.cod_costo_historico_entrada as id_costo',
                'h.tipo_doc',
                'h.cod_almacen',
                'h.costo',
                'h.fecha_emision',
                'h.fecha_registro',
                'a.co_art',
                'a.art_des',
                'a.co_lin',
                'a.co_subl',
                'a.co_cat',
                'a.anulado',
                'm.co_precio',
                'm.monto_min',
                'm.monto_max',
            ])
            ->where('h.tipo_doc', $tipoDoc)
            ->where('a.anulado', 0)
            // Un costo está cubierto si existe un precio vigente generado
            // DESPUÉS de que el costo fue registrado. Si el precio es más
            // antiguo que el costo, hay que recalcular.
            ->whereNotExists(function ($subquery): void {
                $subquery
                    ->select(DB::raw('1'))
                    ->from('dbo.saArtPrecio as p')
                    ->whereColumn('p.co_art', 'a.co_art')
                    ->whereColumn('p.co_precio', 'm.co_precio')
                    ->whereNull('p.hasta')
                    ->whereColumn('p.desde', '>=', 'h.fecha_registro');
            })
            // Solo se procesa el costo más reciente por artículo y tipo de
            // documento, para no insertar dos precios con el mismo instante.
            ->whereNotExists(function ($subquery): void {
                $subquery
                    ->select(DB::raw('1'))
                    ->from('dbo.saCostoHistoricoEntrada as h2')
                    ->whereColumn('h2.cod_articulo_rowguid', 'h.cod_articulo_rowguid')
                    ->whereColumn('h2.tipo_doc', 'h.tipo_doc')
                    ->where(function ($query): void {
                        $query
                            ->whereColumn('h2.fecha_registro', '>', 'h.fecha_registro')
                            ->orWhere(function ($query): void {
                                $query
                                    ->whereColumn('h2.fecha_registro', '=', 'h.fecha_registro')
                                    ->whereColumn('h2.cod_costo_historico_entrada', '>', 'h.cod_costo_historico_entrada');
                            });
                    });
            })
            ->orderBy('a.co_art')
            ->orderBy('m.co_precio');

        if ($coPrecio !== null && $coPrecio !== '') {
            $query->where('m.co_precio', $coPrecio);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Precio final acordado: margen sobre costo, redondeado a 2 decimales.
     */
    public function calcularPrecio(float $costo, float $margenPorcentaje): float
    {
        return round($costo + ($costo * $margenPorcentaje / 100), 2);
    }

    /**
     * Ejecuta el proceso.
     *
     * @return array<string, mixed>
     */
    public function procesar(
        bool $dryRun = true,
        ?string $tipoDoc = null,
        ?string $coPrecio = null,
        ?int $limit = null
    ): array {
        $inicio = microtime(true);
        $tipoDoc = $tipoDoc ?: $this->tipoDoc();

        Log::info('recalcular-precios: inicio', [
            'dry_run' => $dryRun,
            'tipo_doc' => $tipoDoc,
            'co_precio' => $coPrecio,
            'limit' => $limit,
            'connection' => $this->connection(),
        ]);

        if ($dryRun) {
            $pendientes = $this->costosPendientes($tipoDoc, $coPrecio, $limit);
            $this->anotarPrecioCalculado($pendientes);

            $resultado = [
                'dry_run' => true,
                'tipo_doc' => $tipoDoc,
                'total_pendientes' => $pendientes->count(),
                'insertados' => 0,
                'cerrados' => 0,
                'registros' => $pendientes,
            ];
        } else {
            // Lectura + UPDATE + INSERT en una sola transacción atómica.
            $resultado = DB::connection($this->connection())
                ->transaction(function () use ($tipoDoc, $coPrecio, $limit): array {
                    $pendientes = $this->costosPendientes($tipoDoc, $coPrecio, $limit);
                    $this->anotarPrecioCalculado($pendientes);

                    $ahora = Carbon::now();
                    $insertados = 0;
                    $cerrados = 0;

                    foreach ($pendientes as $fila) {
                        $precio = (float) $fila->precio_calculado;

                        $cerrados += $this->cerrarVigenciaAnterior($fila, $ahora);
                        $this->insertarPrecio($fila, $precio, $ahora);

                        $insertados++;

                        Log::debug('recalcular-precios: precio insertado', [
                            'co_art' => trim((string) $fila->co_art),
                            'co_precio' => trim((string) $fila->co_precio),
                            'costo' => (float) $fila->costo,
                            'monto_min' => (float) $fila->monto_min,
                            'precio' => $precio,
                        ]);
                    }

                    return [
                        'dry_run' => false,
                        'tipo_doc' => $tipoDoc,
                        'total_pendientes' => $pendientes->count(),
                        'insertados' => $insertados,
                        'cerrados' => $cerrados,
                        'registros' => $pendientes,
                    ];
                });
        }

        $duracion = (int) round((microtime(true) - $inicio) * 1000);

        Log::info('recalcular-precios: fin', [
            'dry_run' => $resultado['dry_run'],
            'total_pendientes' => $resultado['total_pendientes'],
            'insertados' => $resultado['insertados'],
            'cerrados' => $resultado['cerrados'],
            'duracion_ms' => $duracion,
        ]);

        $resultado['duracion_ms'] = $duracion;

        return $resultado;
    }

    /**
     * Agrega a cada fila el precio que se calcularía (sin escribir en la BD).
     *
     * @param  Collection<int, object>  $pendientes
     */
    private function anotarPrecioCalculado(Collection $pendientes): void
    {
        foreach ($pendientes as $fila) {
            $fila->precio_calculado = $this->calcularPrecio(
                (float) $fila->costo,
                (float) $fila->monto_min
            );
        }
    }

    /**
     * Cierra la vigencia del precio anterior para el mismo artículo y código.
     * Devuelve la cantidad de filas afectadas.
     */
    private function cerrarVigenciaAnterior(object $fila, DateTimeInterface $ahora): int
    {
        return DB::connection($this->connection())
            ->table('dbo.saArtPrecio')
            ->where('co_art', $fila->co_art)
            ->where('co_precio', $fila->co_precio)
            ->whereNull('hasta')
            ->update(['hasta' => $ahora]);
    }

    /**
     * Inserta el nuevo precio vigente.
     *
     * co_alma_calculado es una columna calculada (ISNULL(co_alma,'TODOS')),
     * por eso NO se incluye en el INSERT.
     */
    private function insertarPrecio(object $fila, float $precio, DateTimeInterface $ahora): void
    {
        $usuario = (string) config('profit.usuario_sistema', 'PROFIT');

        DB::connection($this->connection())
            ->table('dbo.saArtPrecio')
            ->insert([
                'co_art' => $fila->co_art,
                'co_precio' => $fila->co_precio,
                'co_alma' => null,
                'desde' => $ahora,
                'hasta' => null,
                'monto' => $precio,
                'precioOm' => (int) config('profit.precio_om', true),
                'co_us_in' => $usuario,
                'co_sucu_in' => null,
                'fe_us_in' => $ahora,
                'co_us_mo' => $usuario,
                'co_sucu_mo' => null,
                'fe_us_mo' => $ahora,
                'revisado' => null,
                'trasnfe' => null,
                'co_mone' => config('profit.co_mone', 'USD'),
                'Inactivo' => 0,
            ]);
    }
}
