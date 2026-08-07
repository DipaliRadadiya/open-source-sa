<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'Compatible con S3',
    ],

    'fields' => [
        'name' => 'Nombre visible',
        'endpoint' => 'URL del endpoint',
        'region' => 'Región',
        'bucket' => 'Bucket',
        'prefix' => 'Prefijo de clave (opcional)',
        'access_key' => 'Clave de acceso',
        'secret_key' => 'Clave secreta',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => 'Una etiqueta corta para distinguir los destinos en la lista de integraciones.',
        'endpoint' => 'Déjalo por defecto para AWS. Configúralo para MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Región donde se encuentra el bucket (solo necesaria para AWS).',
        'prefix' => 'Prefijo de ruta opcional dentro del bucket (sin barra inicial).',
        'access_key' => 'Solo escritura: la API nunca la devuelve.',
    ],

    'status' => [
        'connected' => 'Conectado',
        'never_tested' => 'Aún sin probar',
        'failed' => 'La última prueba falló',
    ],

    'test' => [
        'success' => 'La conexión se estableció correctamente.',
        'failure' => 'No se pudo conectar con el destino.',
        'invalid_credentials' => 'El destino rechazó las credenciales.',
        'unreachable' => 'No se pudo alcanzar el endpoint del destino.',
        'mismatch' => 'El destino escribió y devolvió bytes distintos.',
        'forbidden_host' => 'Esa dirección de endpoint no está permitida.',
        'invalid_endpoint' => 'Introduce una URL de endpoint https:// válida para el bucket.',
    ],

    'delete' => [
        'in_use' => 'No se puede eliminar :name: todavía lo usan :applications. Elimina o redirige esos objetivos de copia de seguridad primero.',
        'and_more' => ':count más',
    ],
];
