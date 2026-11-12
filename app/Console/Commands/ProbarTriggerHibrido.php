<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProbarTriggerHibrido extends Command
{
    protected $signature = 'profit:probar-trigger-hibrido
                            {--force : Permite ejecutar en producción (NO recomendado)}';

    protected $description = 'Inserta un costo de prueba y verifica que el trigger funcione (SOLO entornos no productivos)';

    public function handle(): int
    {
        // Salvaguarda: este comando escribe datos de prueba y cierra vigencias.
        // Nunca debe ejecutarse en producción por accidente.
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Entorno PRODUCCIÓN detectado. Este comando es SOLO para pruebas.');
            $this->error('Si realmente necesitas ejecutarlo, usa --force (bajo tu responsabilidad).');

            return self::FAILURE;
        }

        $this->info('Obteniendo un artículo existente para la prueba...');

        // Obtener un artículo existente
        $articulo = DB::connection('sqlsrv')
            ->table('dbo.saArticulo')
            ->where('anulado', 0)
            ->first();

        if (! $articulo) {
            $this->error('No se encontró ningún artículo');

            return self::FAILURE;
        }

        $this->info('Artículo seleccionado: '.$articulo->co_art.' - '.$articulo->art_des);

        // Obtener el margen del artículo
        $margen = DB::connection('sqlsrv')
            ->table('dbo.saArtMargen')
            ->where('co_art', $articulo->co_art)
            ->first();

        if (! $margen) {
            $this->error('El artículo no tiene margen configurado');

            return self::FAILURE;
        }

        $tipoDoc = 'PROV';

        // Limpiar alertas de prueba anteriores
        $this->info('Limpiando alertas de prueba anteriores...');
        DB::connection('sqlsrv')
            ->table('dbo.saPreciosAlerta')
            ->where('procesado', 0)
            ->delete();

        // Cerrar vigencia de precios existentes para este artículo y código de precio
        $this->info('Cerrando vigencia de precios existentes...');
        DB::connection('sqlsrv')
            ->table('dbo.saArtPrecio')
            ->where('co_art', $articulo->co_art)
            ->where('co_precio', $margen->co_precio)
            ->whereNull('hasta')
            ->update(['hasta' => now()->subMinute()]);

        $this->newLine();
        $this->info('Insertando costo de prueba en saCostoHistoricoEntrada...');

        try {
            // Usar la estructura del script SQL existente
            DB::connection('sqlsrv')->table('dbo.saCostoHistoricoEntrada')->insert([
                'cod_articulo_rowguid' => $articulo->rowguid,
                'cod_almacen' => '01',
                'tipo_doc' => 'PROV',
                'doc_orig' => '00000000-0000-0000-0000-000000000000',
                'cantidad' => 1,
                'cantidad_usada' => 0,
                'costo' => 100.50,
                'costo_pro' => 0,
                'fecha_emision' => now(),
                'fecha_registro' => now(),
                'fecha_recepcion' => null,
                'rengNum' => 1,
            ]);

            // Obtener el último costo insertado para este artículo
            $nuevoCosto = DB::connection('sqlsrv')
                ->table('dbo.saCostoHistoricoEntrada')
                ->where('cod_articulo_rowguid', $articulo->rowguid)
                ->orderBy('fecha_registro', 'desc')
                ->first();

            $codCosto = $nuevoCosto->cod_costo_historico_entrada;
            $this->info('✓ Costo insertado con GUID: '.$codCosto);
        } catch (\Exception $e) {
            $this->error('✗ Error al insertar costo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Verificando si el trigger creó la alerta...');

        $alerta = DB::connection('sqlsrv')
            ->table('dbo.saPreciosAlerta')
            ->where('cod_costo_historico_entrada', $codCosto)
            ->first();

        if ($alerta) {
            $this->info('✓ Alerta creada correctamente');
            $this->line('  - ID: '.$alerta->id);
            $this->line('  - Procesado: '.($alerta->procesado ? 'Sí' : 'No'));
            $this->line('  - Fecha alerta: '.$alerta->fecha_alerta);
        } else {
            $this->error('✗ El trigger NO creó la alerta. Verificar que el trigger esté instalado.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Ejecutando precios:recalcular en modo alerta...');

        $this->call('precios:recalcular', [
            '--tipo-doc' => $tipoDoc,
            '--co-precio' => $margen->co_precio,
        ]);

        $this->newLine();
        $this->info('Verificando estado final de la alerta...');

        $alertaFinal = DB::connection('sqlsrv')
            ->table('dbo.saPreciosAlerta')
            ->where('cod_costo_historico_entrada', $codCosto)
            ->first();

        if ($alertaFinal) {
            $this->line('  - Procesado: '.($alertaFinal->procesado ? 'Sí ✓' : 'No ✗'));
        }

        $this->newLine();
        $this->info('Prueba completada.');

        return self::SUCCESS;
    }
}
