<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class InstalarTriggerHibrido extends Command
{
    protected $signature = 'profit:instalar-trigger-hibrido';

    protected $description = 'Crea la tabla saPreciosAlerta y el trigger TR_CostoEntrada_AlertaPrecio en SQL Server';

    public function handle(): int
    {
        $this->info('Leyendo scripts SQL...');

        $tablaScript = File::get(database_path('sql/crear_tabla_saPreciosAlerta.sql'));
        $triggerScript = File::get(database_path('sql/crear_trigger_TR_CostoEntrada_AlertaPrecio.sql'));

        $this->info('Ejecutando script de tabla saPreciosAlerta...');

        try {
            DB::connection('sqlsrv')->unprepared($tablaScript);
            $this->info('✓ Tabla saPreciosAlerta creada exitosamente');
        } catch (\Exception $e) {
            $this->error('✗ Error al crear tabla: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('Ejecutando DROP del trigger (si existe)...');

        try {
            $dropTrigger = "IF OBJECT_ID('dbo.TR_CostoEntrada_AlertaPrecio', 'TR') IS NOT NULL
                DROP TRIGGER dbo.TR_CostoEntrada_AlertaPrecio;";
            DB::connection('sqlsrv')->unprepared($dropTrigger);
            $this->info('✓ DROP trigger ejecutado');
        } catch (\Exception $e) {
            $this->error('✗ Error en DROP trigger: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->info('Ejecutando CREATE del trigger TR_CostoEntrada_AlertaPrecio...');

        try {
            $createTrigger = "CREATE TRIGGER dbo.TR_CostoEntrada_AlertaPrecio
            ON dbo.saCostoHistoricoEntrada
            AFTER INSERT
            AS
            BEGIN
                SET NOCOUNT ON;

                INSERT INTO dbo.saPreciosAlerta (cod_costo_historico_entrada, fecha_alerta, procesado)
                SELECT cod_costo_historico_entrada, GETDATE(), 0
                FROM inserted;
            END;";
            DB::connection('sqlsrv')->unprepared($createTrigger);
            $this->info('✓ Trigger TR_CostoEntrada_AlertaPrecio creado exitosamente');
        } catch (\Exception $e) {
            $this->error('✗ Error al crear trigger: '.$e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Arquitectura híbrida instalada correctamente.');
        $this->line('- Tabla: dbo.saPreciosAlerta');
        $this->line('- Trigger: TR_CostoEntrada_AlertaPrecio ON dbo.saCostoHistoricoEntrada');

        return self::SUCCESS;
    }
}
