<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => 'Ya hay una sincronización en curso. Espera a que termine antes de iniciar otra.',
    ],

    'reasons' => [
        'unreadable_key' => 'Esta línea no es una clave pública que el panel pueda leer, así que se dejó intacta. Puede seguir concediendo acceso: revísala a mano.',
        'discovery_failed' => 'No se pudo leer del servidor. No se cambió nada.',
        'adopt_failed' => 'Encontrado en el servidor, pero el panel no pudo crear un registro.',
        'requires_system_user' => 'Omitido porque los usuarios del sistema no formaban parte de esta ejecución y hacen falta primero.',
    ],

];
