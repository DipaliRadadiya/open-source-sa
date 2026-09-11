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
    'phpmyadmin_not_deployed' => 'No hay ningún sitio phpMyAdmin instalado en este servidor.',
    'phpmyadmin_no_users' => 'Cree un usuario de base de datos antes de acceder a phpMyAdmin.',
    'remote_users_unsupported' => 'El acceso remoto no está disponible para :engine: sus cuentas no están vinculadas a un host. Usa localhost.',
    'phpmyadmin_not_selectable' => 'El sitio seleccionado no es una instalación activa de phpMyAdmin.',
    'phpmyadmin_user_not_found' => 'El usuario de base de datos especificado no pertenece a esta base de datos.',
    'phpmyadmin_not_isolated' => 'Este sitio phpMyAdmin comparte el grupo de PHP de todo el servidor, por lo que un enlace de inicio de sesión sería legible por todos los demás sitios. Asígnele su propio grupo de PHP, o abra phpMyAdmin e inicie sesión con las credenciales de la base de datos.',
    'phpmyadmin_sso_unavailable' => 'No se pudo preparar el enlace de inicio de sesión en el sitio phpMyAdmin.',

];
