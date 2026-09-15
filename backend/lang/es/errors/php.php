<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version no está instalado.',
    'version_in_use' => 'PHP :version lo usan :apps. Cambie primero esos sitios.',
    'version_is_default' => 'Esta es la versión predeterminada. Elija otra primero.',
    'version_runs_panel' => 'Eliminar PHP :version dejaría el panel fuera de servicio: es la versión con la que funciona el propio panel.',
    'extension_builtin' => 'La extensión :extension está compilada en PHP. No se puede desactivar.',
    'extension_runs_panel' => 'Desactivar :extension dejaría el panel fuera de servicio: necesita :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'Esto no es compatible con la pila de PHP :stack.',

    'ioncube_unsupported_version' => 'ionCube no publica un Loader para PHP :version.',
    'ioncube_unsupported_architecture' => 'ionCube no publica un Loader para la arquitectura de este servidor (:architecture).',
    'ioncube_download_failed' => 'No se pudo descargar el Loader de ionCube. Compruebe el acceso a Internet del servidor e inténtelo de nuevo.',
    'ioncube_invalid_loader' => 'El archivo descargado no es un Loader de ionCube válido para este servidor. No se instaló nada.',
    'ioncube_install_failed' => 'No se pudo instalar el Loader de ionCube.',
    'ioncube_config_test_failed' => 'PHP se negó a iniciarse con el Loader de ionCube, así que se volvió a quitar. Sus sitios no se vieron afectados.',
];
