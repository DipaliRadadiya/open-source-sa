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
        'google_drive' => 'Google Drive',
        'google_drive_oauth' => 'Google Drive (tu propia cuenta)',
        'webdav' => 'WebDAV',
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
        'service_account_json' => 'Clave de cuenta de servicio (JSON)',
        'folder_id' => 'ID de carpeta de la unidad compartida',
        'drive_name' => 'Unidad compartida',
        'client_email' => 'Dirección de la cuenta de servicio',
        'base_uri' => 'URL del servidor',
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
        'endpoint' => 'Déjalo por defecto para AWS. Configúralo para Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces o cualquier servicio compatible con S3.',
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
        'service_account_json' => 'Pega el archivo JSON completo de la cuenta de servicio.',
        'folder_id' => 'La parte de la URL de la carpeta después de /folders/, no el enlace completo.',
        'drive_shared_only' => 'Solo funciona una unidad compartida de Google Workspace. Una cuenta de servicio no tiene almacenamiento propio, así que las subidas a un Drive personal se rechazan aunque la cuenta esté vacía.',
        'drive_share_with' => 'Comparte la carpeta de la unidad compartida con la dirección de la cuenta de servicio antes de probar.',
        'base_uri' => 'La URL completa de WebDAV, incluida la carpeta — por ejemplo https://cloud.ejemplo.com/remote.php/dav/files/tu/',
        'pcloud_warning' => 'pCloud indica que su WebDAV está pensado para archivos pequeños y puede interrumpirse, y deja de funcionar por completo si la cuenta tiene verificación en dos pasos. Ambas cosas importan para las copias: prueba el destino y mantén otro en otro sitio.',
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
        'drive_personal' => 'Esa carpeta está en un Drive personal. Una cuenta de servicio no tiene almacenamiento allí, así que las copias se rechazarían: usa una carpeta de una unidad compartida.',
        'drive_not_shared' => 'La carpeta existe, pero esta cuenta de servicio no tiene acceso a ella.',
        'drive_folder_missing' => 'No se encontró ninguna carpeta con ese ID.',
        'drive_not_a_folder' => 'Ese ID apunta a un archivo, no a una carpeta.',
        'drive_bad_key' => 'No se pudo leer la clave de la cuenta de servicio. Pega el archivo JSON completo.',
        'drive_quota' => 'Google rechazó la subida por falta de cuota de almacenamiento, que es lo que ocurre en un Drive personal.',
        'drive_incomplete' => 'Añade la clave de la cuenta de servicio y el ID de la carpeta antes de probar.',
        'dav_full' => 'El servidor rechazó la subida porque no tiene espacio.',
        'dav_reset' => 'El servidor cerró la conexión sin responder. Si es pCloud, la verificación en dos pasos provoca esto.',
    ],

    'delete' => [
        'in_use' => 'No se puede eliminar :name: todavía lo usan :applications. Elimina o redirige esos objetivos de copia de seguridad primero.',
        'and_more' => ':count más',
    ],

    'validation' => [
        'sftp_auth_required' => 'Indica una contraseña o una clave privada.',
    ],

    'oauth' => [
        'not_connected' => 'Aún no está conectado. Pulse Conectar para autorizar el acceso a su cuenta de Google.',
        'revoked' => 'Google ha revocado este acceso. Suele deberse a que la aplicación OAuth se dejó en «Testing» —Google caduca esos tokens en una semana aproximadamente— o a que se retiró el acceso en myaccount.google.com. Vuelva a conectar.',
        'user_quota' => 'Su Google Drive está lleno. Libere espacio o haga la copia en otro destino.',
        'folder_missing' => 'El panel no puede acceder a la carpeta de copias. Puede que se haya eliminado, o que pertenezca a una cuenta de Google o un cliente OAuth distintos del conectado ahora: el panel solo ve las carpetas que él mismo creó. Conecte de nuevo y creará una nueva.',
        'denied' => 'Se rechazó el acceso en la pantalla de Google. No se ha cambiado nada.',
        'code_expired' => 'Esa aprobación ya se usó, o caducó. Pulse Conectar para empezar de nuevo.',
        'bad_client' => 'Google no reconoce ese ID de cliente. Compruebe que se copió entero, incluida la terminación .apps.googleusercontent.com.',
        'wrong_client_type' => 'Ese cliente es de tipo incorrecto. En Google Cloud Console cree un cliente OAuth del tipo «Web application» y pegue su ID y secreto.',
        'redirect_mismatch' => 'Google rechazó la dirección de redirección. Copie la dirección que aparece bajo el botón Conectar en su cliente OAuth, en «Authorized redirect URIs», exactamente como se muestra.',
        'panel_url_missing' => 'Este panel no conoce su propia dirección web, así que no puede indicar a Google adónde devolverle. Defina FRONTEND_URL en la configuración del panel.',
        'state_invalid' => 'Ese acceso no proviene de este panel. Pulse Conectar y apruebe de nuevo.',
        'state_expired' => 'Ese enlace de acceso ya se usó, o se dejó demasiado tiempo. Pulse Conectar para empezar de nuevo.',
        'destination_missing' => 'Este destino se eliminó mientras usted aprobaba. No se guardó nada.',
        'start_failed' => 'No se pudo iniciar el acceso con Google. Inténtelo de nuevo en un momento.',
        'poll_failed' => 'No se pudo completar el acceso con Google. Inténtelo de nuevo en un momento.',
        'token_failed' => 'No se pudo completar el acceso con Google. Inténtelo de nuevo en un momento.',
        'no_refresh_token' => 'Google autorizó el acceso pero no envió un token duradero, lo que ocurre cuando esta cuenta ya había autorizado la aplicación. Retírela en myaccount.google.com, en Acceso de terceros, y vuelva a conectar.',
        'api_disabled' => 'La API de Google Drive no está habilitada en su proyecto de Google Cloud. Abra APIs y servicios → Biblioteca, busque «Google Drive API» y pulse Habilitar. El acceso funciona sin ella, por eso esto aparece solo ahora.',
        'insufficient_scope' => 'La conexión se aprobó pero no permite crear archivos. Compruebe que la pantalla de consentimiento OAuth incluya el ámbito de Google Drive drive.file y conecte de nuevo.',
        'folder_failed' => 'Conectado, pero no se pudo crear la carpeta de copias en su Drive. El motivo está en los registros del panel, en «storage». Conecte de nuevo cuando se resuelva.',
        'wrong_provider' => 'Este destino no usa el acceso con Google.',
    ],
];
