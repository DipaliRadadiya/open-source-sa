<?php

return [
    'operation_failed' => 'La operación de base de datos falló en el servidor.',
    'export_already_running' => 'Ya hay una exportación de esta base de datos en curso. Espere a que termine antes de iniciar otra.',
    'collation_mismatch' => 'La colación seleccionada no pertenece al conjunto de caracteres elegido.',
    'application_already_attached' => ':application ya tiene vinculada la base de datos :database. Desvincula esa primero o vincula esta base de datos a otra aplicación.',
    'engine_not_accepted' => ':application no puede usar una base de datos :engine. Acepta :accepted.',
    'engine_not_installable' => 'El panel aún no puede instalar este motor de base de datos. Instálelo usted mismo y el panel lo detectará.',
    // The vendor publishes nothing for this Ubuntu release. Refused
    // before the install rather than discovered two minutes into apt.
    'engine_os_unsupported' => ':engine aún no publica paquetes para :os, así que el panel no puede instalarlo aquí. No pasa nada con este servidor: el panel es compatible con :os, pero :engine todavía no ha publicado una versión para ese sistema. Usa otro motor de base de datos o inténtalo de nuevo cuando lo haga.',
    'phpmyadmin_engine_not_supported' => 'phpMyAdmin no admite bases de datos :engine.',
    'phpmyadmin_not_deployed' => 'No hay ninguna aplicación phpMyAdmin instalada en este servidor.',
    'phpmyadmin_no_users' => 'Cree un usuario de base de datos antes de acceder a phpMyAdmin.',
    'remote_users_unsupported' => 'El acceso remoto no está disponible para :engine: sus cuentas no están vinculadas a un host. Usa localhost.',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'Permitir conexiones remotas requiere reiniciar :engine, porque la dirección en la que escucha solo puede cambiarse al iniciar. Las aplicaciones que usan esta base de datos pierden la conexión un momento. Confirme para reiniciarlo y continuar.',
    'phpmyadmin_not_selectable' => 'La aplicación seleccionada no es una instalación activa de phpMyAdmin.',
    'phpmyadmin_user_not_found' => 'El usuario de base de datos especificado no pertenece a esta base de datos.',
    'phpmyadmin_not_isolated' => 'Esta aplicación phpMyAdmin comparte el grupo de PHP de todo el servidor, por lo que un enlace de inicio de sesión sería legible por todas las demás aplicaciones. Asígnele su propio grupo de PHP, o abra phpMyAdmin e inicie sesión con las credenciales de la base de datos.',
    'phpmyadmin_requires_https' => 'Esta aplicación de phpMyAdmin no tiene HTTPS, así que el enlace de inicio de sesión y la sesión de la base de datos viajarían sin cifrar. Emite primero un certificado SSL para ella.',
    'user_exists' => 'Ya existe un usuario de base de datos llamado ":username". Elige otro nombre.',
    'phpmyadmin_sso_unavailable' => 'No se pudo preparar el enlace de inicio de sesión en la aplicación phpMyAdmin.',
    'remote_host_invalid' => 'Introduzca una dirección o un rango IPv4, por ejemplo 203.0.113.5 o 203.0.113.0/24.',
    'remote_host_not_remote' => 'Esa dirección no es remota. Use «Local» para este servidor o «Cualquiera» para todas las direcciones.',
    'engine_unreachable' => ':engine no responde, así que el panel no puede leer esta base de datos. Inicie :engine en la página Servicios y vuelva a intentarlo.',
    'panel_user_protected' => '«:username» es la cuenta que el propio panel usa para gestionar las bases de datos. No se puede cambiar ni eliminar aquí.',
    'export_in_progress' => 'Esta exportación sigue en curso. Espere a que termine y después elimínela.',
    'engine_install_in_progress' => ':engine ya se está instalando. Espere a que termine esa instalación.',
    'panel_process_protected' => 'Esta es la conexión del propio panel. Detenerla haría fallar lo que el panel esté haciendo.',
];
