<?php

return [
    'operation_failed' => 'La operación de base de datos falló en el servidor.',
    'collation_mismatch' => 'La colación seleccionada no pertenece al conjunto de caracteres elegido.',
    'engine_not_installable' => 'El panel aún no puede instalar este motor de base de datos. Instálelo usted mismo y el panel lo detectará.',

    'engine_install' => [
        'package_not_found' => 'El paquete de este motor no está disponible en las fuentes de paquetes de este servidor.',
        'apt_lock' => 'Ya hay otra operación de paquetes en curso. Espere a que termine e inténtelo de nuevo.',
        'no_space' => 'No hay suficiente espacio libre en disco para instalar este motor.',
        'network' => 'El servidor no pudo acceder a sus fuentes de paquetes. Revise su red y DNS.',
        'dpkg_broken' => 'Hay que reparar la base de datos de paquetes de este servidor antes de poder instalar cualquier otra cosa.',
        'port_in_use_by_mysql' => 'MySQL ya está instalado y ocupa este puerto. Elimínelo primero o siga usándolo.',
        'port_in_use_by_mariadb' => 'MariaDB ya está instalado y ocupa este puerto. Elimínelo primero o siga usándolo.',
        'root_unreachable' => 'El motor está instalado pero el panel no pudo iniciar sesión. Su acceso de administrador se ha cambiado respecto al predeterminado, por lo que el panel necesita esos datos para continuar.',
        'grant_failed' => 'El motor está instalado pero el panel no pudo crear su propia cuenta en él.',
        'unknown' => 'La instalación falló. Cite la referencia al soporte.',
    ],
];
