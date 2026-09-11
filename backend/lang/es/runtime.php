<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'No hay ningún paquete para :version en las fuentes de paquetes de este servidor.',
        'apt_lock' => 'Ya se está ejecutando otra operación de paquetes. Inténtalo de nuevo en un momento.',
        'network' => 'No se pudo acceder al repositorio de paquetes. Comprueba que el servidor tenga acceso a la red.',
        'no_space' => 'El servidor se ha quedado sin espacio en disco.',
        'worker' => 'La instalación se detuvo inesperadamente. Puede haber excedido el tiempo — inténtalo de nuevo.',
        'unknown' => 'La instalación falló. Indica la referencia siguiente al soporte.',
        'dpkg_broken' => 'Hay que reparar la base de datos de paquetes de este servidor antes de poder instalar cualquier otra cosa.',
        'port_in_use_by_mysql' => 'MySQL ya está instalado y ocupa este puerto. Elimínelo primero o siga usándolo.',
        'port_in_use_by_mariadb' => 'MariaDB ya está instalado y ocupa este puerto. Elimínelo primero o siga usándolo.',
        'root_unreachable' => 'Está instalado pero el panel no pudo iniciar sesión. Su acceso de administrador se ha cambiado respecto al predeterminado, por lo que el panel necesita esos datos para continuar.',
        'cluster_missing' => 'PostgreSQL está instalado pero no existe ningún clúster en este servidor. Puede que se haya eliminado o que su configuración no finalizara.',
        'grant_failed' => 'Está instalado pero el panel no pudo crear su propia cuenta en él.',
        'repository_failed' => 'No se pudo añadir el repositorio de paquetes de MongoDB. Compruebe que el servidor tiene acceso a repo.mongodb.org.',
        'unreachable' => 'Se instaló pero no llegó a responder. Indique la referencia de abajo al soporte.',
        'auth_required' => 'MongoDB ya está instalado aquí y exige un inicio de sesión que el panel no tiene. Añada sus credenciales en los ajustes de conexión e inténtelo de nuevo.',
        'auth_config_present' => 'MongoDB está instalado y su configuración ya define una sección security. El panel la ha dejado intacta: active authorization usted mismo e inténtelo de nuevo.',
        'auth_failed' => 'Se instaló pero no se pudo activar la autenticación. Indique la referencia de abajo al soporte.',
    ],

    'uninstall_failed' => [
        'failed' => 'No se pudo eliminar PHP :version. Indica la referencia siguiente al soporte.',
        'worker' => 'La eliminación de PHP :version se detuvo inesperadamente. Puede haber excedido el tiempo — inténtalo de nuevo.',
        'unknown' => 'No se pudo eliminar PHP :version. Indica la referencia siguiente al soporte.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'No hay paquete para :extension en PHP :version. Puede que no exista para esta versión.',
        'apt_lock' => 'Ya se está ejecutando otra operación de paquetes. Inténtalo de nuevo en un momento.',
        'network' => 'No se pudo acceder al repositorio de paquetes. Comprueba que el servidor tenga acceso a la red.',
        'no_space' => 'El servidor se ha quedado sin espacio en disco.',
        'worker' => 'La instalación de :extension se detuvo inesperadamente. Puede haber excedido el tiempo — inténtalo de nuevo.',
        'unknown' => 'La instalación de :extension falló. Indica la referencia siguiente al soporte.',
        'enable_failed' => ':extension se instaló pero no se pudo activar. Vuelve a intentarlo con el interruptor.',
    ],

    'fail2ban_install_failed' => [
        'package_not_found' => 'No hay ningún paquete de fail2ban disponible. Comprueba que las fuentes de paquetes del servidor estén configuradas y accesibles.',
        'apt_lock' => 'Ya se está ejecutando otra operación de paquetes. Inténtalo de nuevo en un momento.',
        'network' => 'No se pudo acceder al repositorio de paquetes. Comprueba que el servidor tenga acceso a la red.',
        'no_space' => 'El servidor se ha quedado sin espacio en disco.',
        'worker' => 'La instalación se detuvo inesperadamente. Puede que haya caducado; inténtalo de nuevo.',
        'unknown' => 'La instalación de fail2ban falló. Indica la referencia de abajo al soporte.',
    ],


    /*
    | Per-runtime overrides, consulted before the shared groups above.
    |
    | `install_failed` is shared by PHP, Node, database engines and
    | fail2ban. It used to be worded for PHP alone, so a failed MongoDB
    | install told the user to check the PHP repository. Only the reasons
    | that genuinely differ per runtime belong here; everything else
    | still falls through.
    */

    'php_install_failed' => [
        'package_not_found' => 'No hay paquete para :version. Comprueba que el repositorio de PHP esté configurado y accesible.',
    ],

    'node_install_failed' => [
        'package_not_found' => 'No se encontró Node :version. Comprueba el número de versión o elige una de la lista.',
    ],

    'database_install_failed' => [
        'package_not_found' => 'No hay paquete para :version en este servidor. Comprueba que su repositorio de paquetes esté configurado y accesible.',
        // The repository was added and its index fetched successfully;
        // the engine simply has no build for this Ubuntu release.
        'os_unsupported' => ':version aún no publica paquetes para :os. No pasa nada con este servidor: el panel es compatible con :os, pero :version todavía no ha publicado una versión para ese sistema. Usa otro motor de base de datos o inténtalo de nuevo cuando lo haga.',
    ],

    // Used for :os when /etc/os-release cannot be read.
    'this_server' => 'el sistema operativo de este servidor',
];
