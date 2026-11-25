<?php

use App\Services\Facturacion\Providers\FakeProvider;
use App\Services\Facturacion\Providers\GiorProvider;

return [
    // Tasa de IGV vigente (18% = 16% IGV + 2% IPM)
    'igv' => env('FACTURACION_IGV', '0.18'),

    // Monto a partir del cual una boleta exige identificar al cliente (S/ 700)
    'boleta_monto_identificacion' => env('FACTURACION_BOLETA_MONTO_ID', '700.00'),

    // Días máximos de antigüedad de la fecha de emisión al momento de enviar
    'dias_maximos_envio' => (int) env('FACTURACION_DIAS_MAXIMOS_ENVIO', 3),

    // ¿Cualquiera puede crearse una cuenta y registrar su empresa desde la pantalla de login?
    // Apagado: las empresas las da de alta un superadmin, y cada empresa invita a sus usuarios.
    'registro_abierto' => (bool) env('REGISTRO_ABIERTO', false),

    // ¿Se permite el proveedor "simulado" (no envía nada a SUNAT)?
    // Por seguridad está apagado en producción: un negocio real no puede facturar "de mentira".
    'permitir_simulador' => env('PSE_PERMITIR_SIMULADOR', env('APP_ENV', 'production') !== 'production'),

    // Proveedor por defecto si la empresa no tiene uno configurado: fake | gior
    'proveedor_defecto' => env('PSE_PROVEEDOR', 'fake'),

    'proveedores' => [
        'fake' => FakeProvider::class,
        'gior' => GiorProvider::class,
    ],

    // TODO: Reemplazar con los datos reales de la documentación de Gior Technology
    'gior' => [
        'url_beta' => env('GIOR_URL_BETA', 'https://beta.example-gior.pe/api'),
        'url_produccion' => env('GIOR_URL_PRODUCCION', 'https://api.example-gior.pe/api'),
        'endpoint_emitir' => env('GIOR_ENDPOINT_EMITIR', '/comprobantes'),
        'timeout' => (int) env('GIOR_TIMEOUT', 30),
    ],
];
