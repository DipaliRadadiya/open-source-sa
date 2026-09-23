<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version no está instalado.',

    // Version lookup, the ini editor and its rollback.
    'unknown_version' => 'PHP :version no está instalado en este servidor.',
    'unreadable' => 'No se pudo leer la configuración de PHP :version.',
    'invalid_ini' => 'PHP rechazó esa configuración, así que se restauró la anterior. No se recargó nada.',
    'operation_failed' => 'No se pudo actualizar la configuración de PHP :version.',
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
    'ioncube_discovery_failed' => 'La detección del Loader de ionCube no pudo determinar con seguridad su instalación de PHP, así que no se cambió nada.',
    'ioncube_extraction_failed' => 'No se pudo extraer el archivo de ionCube.',
    'ioncube_removal_failed' => 'No se pudo eliminar el Loader de ionCube que estaba instalado. Consulte la referencia para más detalles.',
    'ioncube_reload_failed' => 'No se pudo recargar PHP. Es posible que los cambios aún no estén activos. Se han conservado todas las copias de recuperación.',
    'ioncube_rollback_failed' => 'La reversión falló; se han conservado todas las copias de recuperación. Se requiere una recuperación manual.',
    'ioncube_config_test_failed' => 'Falló la validación de la configuración de PHP. Se restauraron los archivos de configuración anteriores y no se recargó PHP.',
];
