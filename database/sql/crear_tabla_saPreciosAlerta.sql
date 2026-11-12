-- Fase 6: Tabla de alertas para arquitectura híbrida
-- Esta tabla almacena avisos de nuevos costos insertados
-- El trigger solo inserta aquí, Laravel procesa las alertas
--
-- IDEMPOTENTE Y NO DESTRUCTIVO:
--   Solo crea la tabla si NO existe. Si ya existe, NO la borra ni la
--   recrea, para no perder alertas pendientes en producción.
--   Los índices también se crean solo si no existen.

IF OBJECT_ID('dbo.saPreciosAlerta', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.saPreciosAlerta (
        id INT IDENTITY(1,1) PRIMARY KEY,
        cod_costo_historico_entrada UNIQUEIDENTIFIER NOT NULL,
        fecha_alerta DATETIME NOT NULL DEFAULT GETDATE(),
        procesado BIT NOT NULL DEFAULT 0
    );
END;

-- Índice para consultas rápidas de alertas no procesadas
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_saPreciosAlerta_Procesado'
      AND object_id = OBJECT_ID('dbo.saPreciosAlerta')
)
BEGIN
    CREATE INDEX IX_saPreciosAlerta_Procesado ON dbo.saPreciosAlerta(procesado);
END;

-- Índice para búsquedas por cod_costo_historico_entrada
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = 'IX_saPreciosAlerta_CodCosto'
      AND object_id = OBJECT_ID('dbo.saPreciosAlerta')
)
BEGIN
    CREATE INDEX IX_saPreciosAlerta_CodCosto ON dbo.saPreciosAlerta(cod_costo_historico_entrada);
END;
