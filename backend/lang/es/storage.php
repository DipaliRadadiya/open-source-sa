<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'Compatible con S3',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
    ],

    'fields' => [
        'name' => 'Nombre visible',
        'endpoint' => 'URL del endpoint',
        'region' => 'Región',
        'bucket' => 'Bucket',
        'prefix' => 'Prefijo de clave (opcional)',
        'access_key' => 'Clave de acceso',
        'secret_key' => 'Clave secreta',
        'host' => 'Servidor',
        'port' => 'Puerto',
        'username' => 'Usuario',
        'password' => 'Contraseña',
        'root' => 'Directorio remoto',
        'ssl' => 'Usar TLS (FTPS)',
        'passive' => 'Modo pasivo',
        'private_key' => 'Clave privada',
        'passphrase' => 'Frase de la clave',
        'host_fingerprint' => 'Huella de la clave del servidor',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
        'host' => 'backup.ejemplo.com',
        'root' => 'backups/',
    ],

    'help' => [
        'name' => 'Una etiqueta corta para distinguir los destinos en la lista de integraciones.',
        'endpoint' => 'Déjalo por defecto para AWS. Configúralo para MinIO, R2, Backblaze B2, Wasabi, etc.',
        'region' => 'Región donde se encuentra el bucket (solo necesaria para AWS).',
        'prefix' => 'Prefijo de ruta opcional dentro del bucket (sin barra inicial).',
        'access_key' => 'Solo escritura: la API nunca la devuelve.',
        'host' => 'Nombre de host o dirección IP del servidor que guardará las copias.',
        'port' => 'Déjalo vacío para usar el valor predeterminado.',
        'root' => 'Directorio del servidor donde escribir. Déjalo vacío para usar donde aterrice el inicio de sesión.',
        'ssl' => 'Muy recomendable. Sin él, la contraseña y toda la copia viajan sin cifrar.',
        'passive' => 'Déjalo activado salvo que el servidor exija lo contrario.',
        'private_key' => 'Pega la clave privada completa. Se usa en lugar de una contraseña.',
        'passphrase' => 'Solo si la propia clave privada está cifrada.',
        'host_fingerprint' => 'Se registra la primera vez que el panel conecta y luego se exige. Compárala con la clave del servidor para estar seguro.',
        'plain_ftp_warning' => 'TLS está desactivado. La contraseña y todas las copias se enviarán sin cifrar.',
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
        'invalid_host' => 'Introduce un nombre de host o una dirección IP válidos.',
        'host_key_mismatch' => 'El servidor presentó una clave distinta de la registrada. La conexión se detuvo.',
        'invalid_private_key' => 'No se pudo leer la clave privada. Comprueba que se pegó completa.',
        'root_missing' => 'La carpeta de destino no existe en el servidor. Créala o corrige la ruta de la carpeta.',
    ],

    'delete' => [
        'in_use' => 'No se puede eliminar :name: todavía lo usan :applications. Elimina o redirige esos objetivos de copia de seguridad primero.',
        'and_more' => ':count más',
    ],

    'validation' => [
        'sftp_auth_required' => 'Indica una contraseña o una clave privada.',
    ],
];
