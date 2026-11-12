<?php

namespace App\Http\Controllers;

use App\Services\RecalcularPreciosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class PreciosController extends Controller
{
    public function __construct(
        private readonly RecalcularPreciosService $service
    ) {
    }

    /**
     * Dashboard: resumen, tabla de pendientes e historial de la última corrida.
     */
    public function index(): View
    {
        $pendientes = $this->service->costosPendientes();

        // Se calcula el precio final solo para mostrarlo en la tabla (sin escribir).
        foreach ($pendientes as $fila) {
            $fila->precio_calculado = $this->service->calcularPrecio(
                (float) $fila->costo,
                (float) $fila->monto_min
            );
        }

        return view('precios.index', [
            'pendientes' => $pendientes,
            'totalPendientes' => $pendientes->count(),
            'escrituraHabilitada' => (bool) config('profit.escritura_habilitada', false),
            'tipoDoc' => $this->service->tipoDoc(),
            'conexion' => $this->service->connection(),
            'ultimaEjecucion' => $this->ultimaEjecucion(),
        ]);
    }

    /**
     * Ejecuta el motor en modo escritura y vuelve al dashboard.
     *
     * Siempre se usa modo escaneo (anti-join) para que el botón procese tanto
     * las alertas nuevas del trigger como los costos históricos anteriores a
     * su instalación.
     */
    public function recalcular(): RedirectResponse
    {
        if (! (bool) config('profit.escritura_habilitada', false)) {
            return redirect()
                ->route('precios.index')
                ->with('error', 'La escritura está deshabilitada. Activa PROFIT_ESCRITURA_HABILITADA=true para procesar.');
        }

        try {
            $resultado = $this->service->conModoEscaneo()->procesar(false);

            $insertados = (int) ($resultado['insertados'] ?? 0);
            $cerrados = (int) ($resultado['cerrados'] ?? 0);

            if ($insertados === 0) {
                $mensaje = 'No había precios pendientes por procesar.';
            } else {
                $mensaje = $insertados === 1
                    ? 'Se insertó 1 precio'
                    : 'Se insertaron '.$insertados.' precios';

                if ($cerrados > 0) {
                    $mensaje .= $cerrados === 1
                        ? ' y se cerró 1 vigencia anterior.'
                        : ' y se cerraron '.$cerrados.' vigencias anteriores.';
                } else {
                    $mensaje .= '.';
                }
            }

            return redirect()
                ->route('precios.index')
                ->with('status', $mensaje);
        } catch (Throwable $e) {
            Log::error('precios: error en la interfaz web', [
                'mensaje' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()
                ->route('precios.index')
                ->with('error', 'No se pudo completar el proceso. No se escribió ningún registro. Detalle: '.$e->getMessage());
        }
    }

    /**
     * Lee la última corrida en modo escritura desde storage/logs/laravel.log.
     *
     * @return array{fecha: string, insertados: int, cerrados: int, total_pendientes: int, duracion_ms: int}|null
     */
    private function ultimaEjecucion(): ?array
    {
        $ruta = storage_path('logs/laravel.log');

        if (! is_file($ruta) || ! is_readable($ruta)) {
            return null;
        }

        $tamano = filesize($ruta);

        if ($tamano === false || $tamano === 0) {
            return null;
        }

        // Solo se leen los últimos ~512 KB para no cargar un log enorme.
        $desde = max(0, $tamano - 512000);
        $handle = fopen($ruta, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, $desde);
        $contenido = (string) fread($handle, $tamano - $desde);
        fclose($handle);

        $lineas = preg_split('/\r?\n/', $contenido) ?: [];

        for ($i = count($lineas) - 1; $i >= 0; $i--) {
            $linea = $lineas[$i];

            if (! str_contains($linea, 'recalcular-precios: fin')) {
                continue;
            }

            if (! str_contains($linea, '"dry_run":false')) {
                continue;
            }

            if (! preg_match('/^\[([^\]]+)\].*recalcular-precios: fin\s+(\{.*\})\s*$/', $linea, $matches)) {
                continue;
            }

            $datos = json_decode($matches[2], true);

            if (! is_array($datos)) {
                continue;
            }

            return [
                'fecha' => $matches[1],
                'insertados' => (int) ($datos['insertados'] ?? 0),
                'cerrados' => (int) ($datos['cerrados'] ?? 0),
                'total_pendientes' => (int) ($datos['total_pendientes'] ?? 0),
                'duracion_ms' => (int) ($datos['duracion_ms'] ?? 0),
            ];
        }

        return null;
    }
}
