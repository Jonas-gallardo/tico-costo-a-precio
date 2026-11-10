/* ============================================================================
   Fase 2 - Verificación de la escritura de precios (TICO_A)
   ----------------------------------------------------------------------------
   Uso:
     docker exec -i sqlklk /opt/mssql-tools18/bin/sqlcmd \
       -S localhost -U sa -P 'yatengoUna04?' -C -d TICO_A \
       -i /tmp/verificar_fase2.sql -W -s '|'
   ============================================================================ */

SET NOCOUNT ON;
USE TICO_A;
GO

/* 1. Totales generales ------------------------------------------------------ */
PRINT '=== 1. Totales ===';
SELECT COUNT(*) AS total_saArtPrecio FROM dbo.saArtPrecio;
GO

/* 2. Los 36 precios recién insertados --------------------------------------
   Se identifican por: artículos del histórico PROV + desde >= '2026-11-10'
   y hasta IS NULL.                                                            */
PRINT '=== 2. Precios insertados en esta corrida ===';
SELECT COUNT(*) AS insertados
FROM dbo.saArtPrecio p
WHERE p.co_art IN (
        SELECT DISTINCT a.co_art
        FROM dbo.saCostoHistoricoEntrada h
        JOIN dbo.saArticulo a ON a.rowguid = h.cod_articulo_rowguid
        WHERE h.tipo_doc = 'PROV'
      )
  AND p.hasta IS NULL
  AND p.desde >= '2026-11-10';
GO

/* 3. Anti-join: no debe quedar ningún pendiente ---------------------------- */
PRINT '=== 3. Pendientes restantes (debe ser 0) ===';
SELECT COUNT(*) AS pendientes
FROM dbo.saCostoHistoricoEntrada h
JOIN dbo.saArticulo a ON a.rowguid = h.cod_articulo_rowguid
JOIN dbo.saArtMargen m ON m.co_art = a.co_art
WHERE h.tipo_doc = 'PROV'
  AND a.anulado = 0
  AND NOT EXISTS (
        SELECT 1 FROM dbo.saArtPrecio p
        WHERE p.co_art = a.co_art
          AND p.co_precio = m.co_precio
          AND p.hasta IS NULL
  );
GO

/* 4. Precios vigentes anteriores cerrados (hasta = NOW()) ------------------
   En esta base los 9 artículos no tenían precio previo, por lo que el
   UPDATE afectó 0 filas. Se deja la consulta para auditoría futura.           */
PRINT '=== 4. Filas con hasta no nulo para los 9 artículos ===';
SELECT COUNT(*) AS cerrados
FROM dbo.saArtPrecio p
WHERE p.co_art IN (
        SELECT DISTINCT a.co_art
        FROM dbo.saCostoHistoricoEntrada h
        JOIN dbo.saArticulo a ON a.rowguid = h.cod_articulo_rowguid
        WHERE h.tipo_doc = 'PROV'
      )
  AND p.hasta IS NOT NULL;
GO

/* 5. Detalle de los 36 precios: comparación contra la fórmula -------------- */
PRINT '=== 5. Detalle y validación de fórmula ===';
SELECT
    p.co_art,
    p.co_precio,
    p.co_alma,
    p.co_alma_calculado,
    p.monto,
    CAST(h.costo + h.costo * m.monto_min / 100 AS decimal(18, 2)) AS monto_esperado,
    CASE
        WHEN p.monto = CAST(h.costo + h.costo * m.monto_min / 100 AS decimal(18, 2))
        THEN 'OK' ELSE 'DIFERENCIA'
    END AS validacion,
    p.co_mone,
    p.precioOm,
    p.co_us_in,
    p.co_sucu_in,
    p.Inactivo,
    p.desde,
    p.hasta
FROM dbo.saArtPrecio p
JOIN dbo.saCostoHistoricoEntrada h
        ON h.cod_articulo_rowguid = (SELECT rowguid FROM dbo.saArticulo WHERE co_art = p.co_art)
JOIN dbo.saArtMargen m
        ON m.co_art = p.co_art AND m.co_precio = p.co_precio
WHERE p.hasta IS NULL
  AND p.desde >= '2026-11-10'
ORDER BY p.co_art, p.co_precio;
GO

/* 6. Comprobaciones de integridad del INSERT ------------------------------- */
PRINT '=== 6. Integridad del INSERT (todo debe dar 0) ===';
SELECT
    SUM(CASE WHEN co_alma IS NOT NULL THEN 1 ELSE 0 END)          AS co_alma_no_null,
    SUM(CASE WHEN co_alma_calculado <> 'TODOS' THEN 1 ELSE 0 END) AS alma_calculado_incorrecto,
    SUM(CASE WHEN Inactivo <> 0 THEN 1 ELSE 0 END)                AS inactivo_incorrecto,
    SUM(CASE WHEN hasta IS NOT NULL THEN 1 ELSE 0 END)            AS hasta_no_null,
    SUM(CASE WHEN co_mone <> 'USD' THEN 1 ELSE 0 END)             AS moneda_incorrecta,
    SUM(CASE WHEN precioOm <> 1 THEN 1 ELSE 0 END)                AS precio_om_incorrecto
FROM dbo.saArtPrecio
WHERE hasta IS NULL
  AND desde >= '2026-11-10';
GO
