/* ============================================================================
   Prueba E2E - Simulación de una factura de compra (TICO_A)
   ----------------------------------------------------------------------------
   Inserta un nuevo costo de entrada para KOLITA (7591031003359) con costo
   10.00, tipo_doc = 'PROV', usando el rowguid real del artículo.

   Uso:
     docker exec -i sqlklk /opt/mssql-tools18/bin/sqlcmd \
       -S localhost -U sa -P 'yatengoUna04?' -C -d TICO_A \
       -i /tmp/simular_compra_e2e.sql -W -s '|'
   ============================================================================ */

SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
SET NOCOUNT ON;
USE TICO_A;
GO

DECLARE @rowguid UNIQUEIDENTIFIER = (
    SELECT rowguid FROM dbo.saArticulo WHERE co_art = '7591031003359'
);

IF @rowguid IS NULL
BEGIN
    THROW 50001, 'No se encontró el artículo KOLITA (7591031003359).', 1;
END;

INSERT INTO dbo.saCostoHistoricoEntrada
    (
        cod_articulo_rowguid,
        cod_almacen,
        tipo_doc,
        doc_orig,
        cantidad,
        cantidad_usada,
        costo,
        costo_pro,
        fecha_emision,
        fecha_registro,
        fecha_recepcion,
        rengNum
    )
VALUES
    (
        @rowguid,
        '01',
        'PROV',
        '00000000-0000-0000-0000-000000000000',
        1,
        0,
        10.00,
        0,
        GETDATE(),
        GETDATE(),
        NULL,
        1
    );

SELECT
    cod_costo_historico_entrada,
    cod_articulo_rowguid,
    cod_almacen,
    tipo_doc,
    costo,
    fecha_emision,
    fecha_registro,
    rengNum
FROM dbo.saCostoHistoricoEntrada
WHERE cod_articulo_rowguid = @rowguid
ORDER BY fecha_registro;
GO
