<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Conexión a la base de datos de Profit (TICO_A)
    |--------------------------------------------------------------------------
    |
    | Se usa una conexión dedicada para dejar claro que este proceso escribe
    | (en Fase 2) sobre el esquema del ERP y no sobre las tablas de Laravel.
    |
    */

    'connection' => env('PROFIT_DB_CONNECTION', 'sqlsrv'),

    /*
    |--------------------------------------------------------------------------
    | Tipo de documento del histórico de entrada
    |--------------------------------------------------------------------------
    |
    | El cliente hablaba de 'PROC', pero en la base restaurada el único valor
    | presente es 'PROV' (generado por pActualizarPrecioCostoManual cuando
    | @sCo_TipoCosto = '6'). Se deja configurable para no reescribir el
    | servicio si en producción usan otro valor.
    |
    | 'PROV' = costo de proveedor   (tipo_costo '6')
    | 'REPO' = costo de reposición  (tipo_costo '5')
    | 'PROC' = valor reportado por el cliente, no existe en esta BD
    |
    */

    'tipo_doc_default' => env('PROFIT_TIPO_DOC', 'PROV'),

    'tipos_doc_entrada' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PROFIT_TIPOS_DOC_ENTRADA', 'PROV,PROC'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Códigos de precio (saTipoPrecio)
    |--------------------------------------------------------------------------
    |
    | En esta base: 01 PROFORMA, 02 PROFORMA MAYOR, 03 FACTURA, 04 FACTURA MAYOR.
    |
    */

    'precios' => ['01', '02', '03', '04'],

    /*
    |--------------------------------------------------------------------------
    | Modo de margen configurado en el ERP (par_emp.c_margen_costo_precio)
    |--------------------------------------------------------------------------
    |
    | 1 = el margen se aplica sobre el costo (markup):
    |         precio = costo + costo * margen / 100
    | 0 = el margen se aplica sobre el precio (margen comercial):
    |         precio = 100 * costo / (100 - margen)
    |
    | Valor real verificado en TICO_A: 1.
    |
    */

    'margen_costo_precio' => (bool) env('PROFIT_MARGEN_COSTO_PRECIO', true),

    /*
    |--------------------------------------------------------------------------
    | Convenciones de auditoría y moneda de saArtPrecio
    |--------------------------------------------------------------------------
    |
    | Verificado en TICO_A: las 10.620 filas existentes usan
    | co_us_in/co_us_mo = 'PROFIT', co_mone = 'USD' y precioOm = 1.
    | co_sucu_in / co_sucu_mo quedan en NULL.
    |
    */

    'usuario_sistema' => env('PROFIT_USUARIO_SISTEMA', 'PROFIT'),

    'co_mone' => env('PROFIT_CO_MONE', 'USD'),

    'precio_om' => (int) env('PROFIT_PRECIO_OM', 1),

    /*
    |--------------------------------------------------------------------------
    | Interruptor de escritura
    |--------------------------------------------------------------------------
    |
    | Mientras esto sea false, el comando siempre se ejecuta como dry-run
    | aunque no se pase --dry-run. Debe activarse explícitamente para que
    | el proceso escriba en saArtPrecio.
    |
    */

    'escritura_habilitada' => (bool) env('PROFIT_ESCRITURA_HABILITADA', false),

];
