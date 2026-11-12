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
 * Fase 6 (Arquitectura Híbrida):
 *   - Modo alerta: lee desde dbo.saPreciosAlerta donde procesado=0.
 *   - Modo escaneo: anti-join general; es el modo operativo del botón web y
 *     del scheduler porque también detecta costos anteriores al trigger.
 *   - Ambos modos marcan las alertas como procesado=1. En modo escaneo se
 *     limpian todas las alertas pendientes después de una corrida exitosa.
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
        private readonly bool $modoEscaneo = false,
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

    public function modoEscaneo(): bool
    {
        return $this->modoEscaneo;
    }

    /**
     * Devuelve una instancia equivalente forzando el modo de detección.
     *
     * Se usa desde el botón web y el scheduler para garantizar que ambos
     * caminos operativos trabajen siempre con el anti-join (modo escaneo).
     */
    public function conModoEscaneo(bool $modoEscaneo = true): self
    {
        return new self($this->connection, $this->tipoDoc, $modoEscaneo);
    }

    /**
     * Costos de entrada que requieren un precio nuevo (modo alerta).
     *
     * Lee desde dbo.saPreciosAlerta donde procesado=0.
     * Join con las tablas necesarias para obtener datos del costo.
     *
     * @return Collection<int, object>
     */
    public function costosPendientesAlerta(
        ?string $tipoDoc = null,
        ?string $coPrecio = null,
        ?int $limit = null
    ): Collection {
        $tipoDoc = $tipoDoc ?: $this->tipoDoc();

        $query = DB::connection($this->connection())
            ->table('dbo.saPreciosAlerta as alerta')
            ->join('dbo.saCostoHistoricoEntrada as h', 'h.cod_costo_historico_entrada', '=', 'alerta.cod_costo_historico_entrada')
            ->join('dbo.saArticulo as a', 'a.rowguid', '=', 'h.cod_articulo_rowguid')
            ->join('dbo.saArtMargen as m', 'm.co_art', '=', 'a.co_art')
            ->select([
                'alerta.id as alerta_id',
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
            ->where('alerta.procesado', 0)
            ->where('h.tipo_doc', $tipoDoc)
            ->where('a.anulado', 0);

        if ($coPrecio !== null && $coPrecio !== '') {
            $query->where('m.co_precio', $coPrecio);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Costos de entrada que requieren un precio nuevo (modo escaneo/fallback).
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
        $usarModoEscaneo = $this->modoEscaneo();

        Log::info('recalcular-precios: inicio', [
            'dry_run' => $dryRun,
            'tipo_doc' => $tipoDoc,
            'co_precio' => $coPrecio,
            'limit' => $limit,
            'connection' => $this->connection(),
            'modo' => $usarModoEscaneo ? 'escaneo' : 'alerta',
        ]);

        if ($dryRun) {
            $pendientes = $usarModoEscaneo
                ? $this->costosPendientes($tipoDoc, $coPrecio, $limit)
                : $this->costosPendientesAlerta($tipoDoc, $coPrecio, $limit);
            $this->anotarPrecioCalculado($pendientes);

            $resultado = [
                'dry_run' => true,
                'tipo_doc' => $tipoDoc,
                'modo' => $usarModoEscaneo ? 'escaneo' : 'alerta',
                'total_pendientes' => $pendientes->count(),
                'insertados' => 0,
                'cerrados' => 0,
                'alertas_procesadas' => 0,
                'registros' => $pendientes,
            ];
        } else {
            // Lectura + UPDATE + INSERT en una sola transacción atómica.
            $resultado = DB::connection($this->connection())
                ->transaction(function () use ($tipoDoc, $coPrecio, $limit, $usarModoEscaneo): array {
                    $pendientes = $usarModoEscaneo
                        ? $this->costosPendientes($tipoDoc, $coPrecio, $limit)
                        : $this->costosPendientesAlerta($tipoDoc, $coPrecio, $limit);
                    $this->anotarPrecioCalculado($pendientes);

                    $ahora = Carbon::now();
                    $insertados = 0;
                    $cerrados = 0;
                    $alertasProcesadas = 0;
                    $costosProcesados = [];

                    foreach ($pendientes as $fila) {
                        $precio = (float) $fila->precio_calculado;

                        $cerrados += $this->cerrarVigenciaAnterior($fila, $ahora);
                        $this->insertarPrecio($fila, $precio, $ahora);

                        // En modo alerta cada alerta se cierra individualmente.
                        if (! $usarModoEscaneo && isset($fila->alerta_id)) {
                            $alertasProcesadas += $this->marcarAlertaProcesada((int) $fila->alerta_id);
                        }

                        if ($usarModoEscaneo) {
                            $costosProcesados[] = (string) $fila->id_costo;
                        }

                        $insertados++;

                        Log::debug('recalcular-precios: precio insertado', [
                            'co_art' => trim((string) $fila->co_art),
                            'co_precio' => trim((string) $fila->co_precio),
                            'costo' => (float) $fila->costo,
                            'monto_min' => (float) $fila->monto_min,
                            'precio' => $precio,
                        ]);
                    }

                    // En una corrida completa el escaneo es la fuente de
                    // verdad: cualquier alerta pendiente (incluso de costos
                    // antiguos o de artículos ya cubiertos) se marca para no
                    // dejar basura. Si hay filtros (--co-precio / --limit),
                    // solo se cierran las alertas de los costos procesados.
                    if ($usarModoEscaneo) {
                        $corridaCompleta = ($coPrecio === null || $coPrecio === '')
                            && ($limit === null || $limit <= 0);

                        $alertasProcesadas = $this->marcarAlertasProcesadas(
                            $corridaCompleta ? null : $costosProcesados
                        );
                    }

                    return [
                        'dry_run' => false,
                        'tipo_doc' => $tipoDoc,
                        'modo' => $usarModoEscaneo ? 'escaneo' : 'alerta',
                        'total_pendientes' => $pendientes->count(),
                        'insertados' => $insertados,
                        'cerrados' => $cerrados,
                        'alertas_procesadas' => $alertasProcesadas,
                        'registros' => $pendientes,
                    ];
                });
        }

        $duracion = (int) round((microtime(true) - $inicio) * 1000);

        Log::info('recalcular-precios: fin', [
            'dry_run' => $resultado['dry_run'],
            'modo' => $resultado['modo'],
            'total_pendientes' => $resultado['total_pendientes'],
            'insertados' => $resultado['insertados'],
            'cerrados' => $resultado['cerrados'],
            'alertas_procesadas' => $resultado['alertas_procesadas'],
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

    /**
     * Marca una alerta como procesada. Devuelve las filas afectadas.
     */
    private function marcarAlertaProcesada(int $alertaId): int
    {
        return DB::connection($this->connection())
            ->table('dbo.saPreciosAlerta')
            ->where('id', $alertaId)
            ->update(['procesado' => 1]);
    }

    /**
     * Marca alertas pendientes como procesadas.
     *
     * Si $costos es null se limpian todas (corrida completa). Si se pasa una
     * lista, solo se cierran las alertas de esos costos (corridas filtradas).
     *
     * El modo escaneo no depende de la tabla de alertas, así que si la tabla
     * no existe (trigger aún no instalado) el proceso de precios NO debe
     * fallar por esta limpieza.
     *
     * @param  array<int, string>|null  $costos
     */
    private function marcarAlertasProcesadas(?array $costos = null): int
    {
        if ($costos === []) {
            return 0;
        }

        try {
            $query = DB::connection($this->connection())
                ->table('dbo.saPreciosAlerta')
                ->where('procesado', 0);

            if ($costos !== null) {
                $query->whereIn('cod_costo_historico_entrada', $costos);
            }

            return $query->update(['procesado' => 1]);
        } catch (\Throwable $e) {
            Log::warning('recalcular-precios: no se pudieron marcar las alertas', [
                'mensaje' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
