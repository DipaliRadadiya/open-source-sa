<?php

return [
    'registry_status' => [
        'connected' => 'Conectado',
        'never_tested' => 'Sin probar todavía',
        'failed' => 'La última prueba falló',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'El registro rechazó este usuario y este token.',
        'unreachable' => 'No se pudo contactar con el registro desde este servidor. Compruebe la dirección y si es accesible por HTTPS.',
        'unknown' => 'El registro rechazó la conexión sin indicar el motivo. La respuesta de Docker está en el registro de operaciones del servidor.',
    ],

    // Image discovery (DS-02): search, versions and inspect on the create form.
    'image' => [
        'invalid_reference' => 'Ese no es un nombre de imagen que Docker acepte. Usa un nombre como nginx, usememos/memos o ghcr.io/owner/app, seguido opcionalmente de :versión. Los nombres de repositorio van en minúsculas.',
        'not_found' => 'No se encontró ninguna imagen llamada :image. Revisa cómo está escrita o, si es privada, elige la credencial de registro que puede leerla.',
        'tag_not_found' => 'La imagen :image no tiene la versión :tag. Elige una de la lista de versiones.',
        'credential_rejected' => 'La credencial de registro elegida no puede leer :image. Comprueba que la imagen existe y que el token puede descargarla.',
        'registry_unreachable' => 'No se pudo contactar con el registro :registry desde este servidor, así que no se pudo comprobar la imagen. Aún puedes indicar el puerto tú mismo.',
        'rate_limited' => 'Docker Hub está limitando la frecuencia con la que este servidor puede consultar. Inténtalo de nuevo en unos minutos o indica el puerto tú mismo.',
        'blocked_host' => 'El panel no se conecta a esa dirección de registro. Se rechazan las direcciones de loopback y de enlace local.',
        'warning_required_env' => 'Esta imagen necesita estos ajustes para arrancar: :keys.',
        'warning_empty_env' => 'Estos ajustes están vacíos en la imagen. Rellénalos solo si la documentación de la imagen lo pide: :keys.',
        'warning_no_build' => 'Esta imagen no tiene compilación para :architecture, así que no funcionará en este servidor.',
        'warning_large' => 'Esta imagen ocupa :size de descarga. El primer arranque tardará un rato.',
        'warning_no_port' => 'Esta imagen no indica en qué puerto escucha. Introduce el puerto que indica su documentación.',
        'warning_several_ports' => 'Esta imagen escucha en varios puertos (:ports). Se eligió el puerto :port para el sitio web; cámbialo si la documentación dice otra cosa.',
    ],
];
