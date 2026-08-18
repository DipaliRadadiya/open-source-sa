<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'No hay paquete para :version. Comprueba que el repositorio de PHP esté configurado y accesible.',
        'apt_lock' => 'Ya se está ejecutando otra operación de paquetes. Inténtalo de nuevo en un momento.',
        'network' => 'No se pudo acceder al repositorio de paquetes. Comprueba que el servidor tenga acceso a la red.',
        'no_space' => 'El servidor se ha quedado sin espacio en disco.',
        'worker' => 'La instalación se detuvo inesperadamente. Puede haber excedido el tiempo — inténtalo de nuevo.',
        'unknown' => 'La instalación falló. Indica la referencia siguiente al soporte.',
        'dpkg_broken' => 'Hay que reparar la base de datos de paquetes de este servidor antes de poder instalar cualquier otra cosa.',
        'port_in_use_by_mysql' => 'MySQL ya está instalado y ocupa este puerto. Elimínelo primero o siga usándolo.',
        'port_in_use_by_mariadb' => 'MariaDB ya está instalado y ocupa este puerto. Elimínelo primero o siga usándolo.',
        'root_unreachable' => 'Está instalado pero el panel no pudo iniciar sesión. Su acceso de administrador se ha cambiado respecto al predeterminado, por lo que el panel necesita esos datos para continuar.',
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

];
