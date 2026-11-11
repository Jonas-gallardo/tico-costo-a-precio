-- Fase 6: Tabla de alertas para arquitectura híbrida
-- Esta tabla almacena avisos de nuevos costos insertados
-- El trigger solo inserta aquí, Laravel procesa las alertas

IF OBJECT_ID('dbo.saPreciosAlerta', 'U') IS NOT NULL
    DROP TABLE dbo.saPreciosAlerta;

CREATE TABLE dbo.saPreciosAlerta (
    id INT IDENTITY(1,1) PRIMARY KEY,
    cod_costo_historico_entrada UNIQUEIDENTIFIER NOT NULL,
    fecha_alerta DATETIME NOT NULL DEFAULT GETDATE(),
    procesado BIT NOT NULL DEFAULT 0
);

-- Índice para consultas rápidas de alertas no procesadas
CREATE INDEX IX_saPreciosAlerta_Procesado ON dbo.saPreciosAlerta(procesado);

-- Índice para búsquedas por cod_costo_historico_entrada
CREATE INDEX IX_saPreciosAlerta_CodCosto ON dbo.saPreciosAlerta(cod_costo_historico_entrada);
