<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class InstalarTriggerHibrido extends Command
{
    protected $signature = 'profit:instalar-trigger-hibrido
                            {--force : Ejecuta sin pedir confirmación (necesario en producción)}';

    protected $description = 'Crea (si no existe) la tabla saPreciosAlerta y el trigger TR_CostoEntrada_AlertaPrecio en SQL Server';

    public function handle(): int
    {
        // Salvaguarda: en producción exigir --force para evitar ejecuciones accidentales.
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Entorno PRODUCCIÓN detectado. Ejecuta con --force para confirmar la instalación.');

            return self::FAILURE;
        }

        $this->info('Leyendo scripts SQL...');

        $tablaScript = File::get(database_path('sql/crear_tabla_saPreciosAlerta.sql'));
        $triggerScript = File::get(database_path('sql/crear_trigger_TR_CostoEntrada_AlertaPrecio.sql'));

        // 1) Tabla: el script es idempotente y NO destructivo (solo crea si no existe).
        $this->info('Ejecutando script de tabla saPreciosAlerta (idempotente, no destructivo)...');

        try {
            DB::connection('sqlsrv')->unprepared($tablaScript);
            $this->info('✓ Tabla saPreciosAlerta verificada/creada exitosamente');
        } catch (\Exception $e) {
            $this->error('✗ Error al crear tabla: '.$e->getMessage());

            return self::FAILURE;
        }

        // 2) Trigger: el script hace DROP TRIGGER IF EXISTS + CREATE (idempotente).
        //    Recrear el trigger NO borra datos; solo reemplaza la definición.
        $this->info('Ejecutando script del trigger TR_CostoEntrada_AlertaPrecio (idempotente)...');

        try {
            DB::connection('sqlsrv')->unprepared($triggerScript);
            $this->info('✓ Trigger TR_CostoEntrada_AlertaPrecio creado exitosamente');
        } catch (\Exception $e) {
            $this->error('✗ Error al crear trigger: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Arquitectura híbrida instalada correctamente.');
        $this->line('- Tabla: dbo.saPreciosAlerta (datos existentes preservados)');
        $this->line('- Trigger: TR_CostoEntrada_AlertaPrecio ON dbo.saCostoHistoricoEntrada');

        return self::SUCCESS;
    }
}
