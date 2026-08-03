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

];
