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
    ],

    'extension_install_failed' => [
        'package_not_found' => 'No hay paquete para :extension en PHP :version. Puede que no exista para esta versión.',
        'apt_lock' => 'Ya se está ejecutando otra operación de paquetes. Inténtalo de nuevo en un momento.',
        'network' => 'No se pudo acceder al repositorio de paquetes. Comprueba que el servidor tenga acceso a la red.',
        'no_space' => 'El servidor se ha quedado sin espacio en disco.',
        'worker' => 'La instalación de :extension se detuvo inesperadamente. Puede haber excedido el tiempo — inténtalo de nuevo.',
        'unknown' => 'La instalación de :extension falló. Indica la referencia siguiente al soporte.',
    ],

];
