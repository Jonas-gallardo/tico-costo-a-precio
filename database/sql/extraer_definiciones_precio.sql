/* ============================================================================
   Fase 1 - Extracción de definiciones del motor de precios (Profit / TICO_A)
   ----------------------------------------------------------------------------
   Uso:
     docker exec -i sqlklk /opt/mssql-tools18/bin/sqlcmd \
       -S localhost -U sa -P 'yatengoUna04?' -C -d TICO_A \
       -i /var/opt/mssql/backup/...   (o copiar el archivo al contenedor)

   Alternativa directa:
     docker exec sqlklk /opt/mssql-tools18/bin/sqlcmd \
       -S localhost -U sa -P 'yatengoUna04?' -C -d TICO_A -y 0 -Y 0 \
       -Q "SET NOCOUNT ON; SELECT OBJECT_DEFINITION(OBJECT_ID('dbo.pActualizarPrecioCostoAutomatico'));"
   ============================================================================ */

USE TICO_A;
GO

PRINT '===== pActualizarPrecioCostoAutomatico =====';
EXEC sp_helptext 'dbo.pActualizarPrecioCostoAutomatico';
GO

PRINT '===== pActualizarPrecioCostoManual (aquí está la fórmula) =====';
EXEC sp_helptext 'dbo.pActualizarPrecioCostoManual';
GO

PRINT '===== pObtenerListaPrecioCostoAutomatico =====';
EXEC sp_helptext 'dbo.pObtenerListaPrecioCostoAutomatico';
GO

PRINT '===== CalcularPorcentajeGanancia (margen real) =====';
EXEC sp_helptext 'dbo.CalcularPorcentajeGanancia';
GO

PRINT '===== Estructura saAjPrecioCostoM =====';
EXEC sp_help 'dbo.saAjPrecioCostoM';
GO

PRINT '===== Estructura saAjPrecioCostoAuto =====';
EXEC sp_help 'dbo.saAjPrecioCostoAuto';
GO

PRINT '===== Estructura saAjPrecioCostoReng =====';
EXEC sp_help 'dbo.saAjPrecioCostoReng';
GO

/* ----------------------------------------------------------------------------
   Consulta de pendientes equivalente al anti-join del servicio Laravel.
   ---------------------------------------------------------------------------- */
PRINT '===== Costos pendientes (anti-join saArtPrecio) =====';

SELECT
    h.cod_costo_historico_entrada AS id_costo,
    h.tipo_doc,
    h.cod_almacen,
    h.costo,
    h.fecha_emision,
    a.co_art,
    a.art_des,
    a.co_lin,
    m.co_precio,
    m.monto_min,
    m.monto_max
FROM dbo.saCostoHistoricoEntrada AS h
INNER JOIN dbo.saArticulo AS a
        ON a.rowguid = h.cod_articulo_rowguid
INNER JOIN dbo.saArtMargen AS m
        ON m.co_art = a.co_art
WHERE h.tipo_doc = 'PROV'
  AND a.anulado = 0
  AND NOT EXISTS (
        SELECT 1
        FROM dbo.saArtPrecio AS p
        WHERE p.co_art = a.co_art
          AND p.co_precio = m.co_precio
          AND p.hasta IS NULL
  )
ORDER BY a.co_art, m.co_precio;
GO
