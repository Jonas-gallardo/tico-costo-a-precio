-- Fase 6: Trigger para arquitectura híbrida
-- Este trigger se dispara cuando se inserta un costo en saCostoHistoricoEntrada
-- Su única función es insertar un aviso en saPreciosAlerta
-- REGLA ESTRICTA: Cero cálculos, cero consultas a otras tablas
-- Solo INSERT del cod_costo_historico_entrada para que sea ultra rápido y seguro

IF OBJECT_ID('dbo.TR_CostoEntrada_AlertaPrecio', 'TR') IS NOT NULL
    DROP TRIGGER dbo.TR_CostoEntrada_AlertaPrecio;

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
