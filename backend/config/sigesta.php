<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Empresa (encabezado de los reportes)
    |--------------------------------------------------------------------------
    */

    'empresa' => [
        'nombre' => env('SIGESTA_EMPRESA', 'Planta de alimentos'),
        'nit' => env('SIGESTA_EMPRESA_NIT'),
        'direccion' => env('SIGESTA_EMPRESA_DIRECCION', 'Cochabamba, Bolivia'),
        'registro_sanitario' => env('SIGESTA_REGISTRO_SANITARIO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reportes
    |--------------------------------------------------------------------------
    | Filas máximas por reporte. Un PDF de miles de filas no se lee; para
    | volúmenes grandes se usa Excel.
    */

    'reportes' => [
        'max_filas_pdf' => (int) env('SIGESTA_REPORTE_MAX_PDF', 2000),
        'max_filas_xlsx' => (int) env('SIGESTA_REPORTE_MAX_XLSX', 20000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sesiones de usuario
    |--------------------------------------------------------------------------
    | Minutos de validez del token emitido en /auth/login (8 h = una jornada).
    */

    'token_expiracion_minutos' => (int) env('SIGESTA_TOKEN_MINUTOS', 480),

    /*
    |--------------------------------------------------------------------------
    | Anulaciones
    |--------------------------------------------------------------------------
    | Horas durante las que un despacho o un ingreso de compra puede anularse
    | por error de registro. Pasado ese plazo se corrige con un ajuste (merma).
    */

    'anulacion_horas' => (int) env('SIGESTA_ANULACION_HORAS', 24),

    /*
    |--------------------------------------------------------------------------
    | Servicio de IA (FastAPI + Groq)
    |--------------------------------------------------------------------------
    | Usuario técnico dueño del token de solo lectura (php artisan sigesta:token-ia).
    */

    'ia_service' => [
        'email' => env('SIGESTA_IA_EMAIL', 'ia-service@sigesta.internal'),
        'nombre' => 'Asistente IA (servicio)',
        'token_dias' => (int) env('SIGESTA_IA_TOKEN_DIAS', 90),
    ],

];
