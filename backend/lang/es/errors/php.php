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
];
