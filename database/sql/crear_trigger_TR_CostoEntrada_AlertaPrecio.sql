-- Fase 6: Trigger para arquitectura híbrida
-- Este trigger se dispara cuando se inserta un costo en saCostoHistoricoEntrada
-- Su única función es insertar un aviso en saPreciosAlerta
-- REGLA ESTRICTA: Cero cálculos, cero consultas a otras tablas
-- Solo INSERT del cod_costo_historico_entrada para que sea ultra rápido y seguro
--
-- IDEMPOTENTE Y EJECUTABLE EN UN SOLO BATCH:
--   SQL Server exige que CREATE TRIGGER sea la PRIMERA sentencia de su batch.
--   Por eso el DROP y el CREATE se ejecutan mediante EXEC() (batches dinámicos),
--   lo que permite correr este script varias veces sin romperse.
--   Recrear el trigger NO borra datos: solo reemplaza su definición.

IF OBJECT_ID('dbo.TR_CostoEntrada_AlertaPrecio', 'TR') IS NOT NULL
    EXEC('DROP TRIGGER dbo.TR_CostoEntrada_AlertaPrecio');

EXEC('
CREATE TRIGGER dbo.TR_CostoEntrada_AlertaPrecio
ON dbo.saCostoHistoricoEntrada
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    -- Insertar alerta para cada fila insertada
    -- Solo el cod_costo_historico_entrada, nada más
    INSERT INTO dbo.saPreciosAlerta (cod_costo_historico_entrada, fecha_alerta, procesado)
    SELECT cod_costo_historico_entrada, GETDATE(), 0
    FROM inserted;
END;
');
