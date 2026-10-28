<?php

return [
    'none' => 'ninguno',
    'yes' => 'sí',
    'no' => 'no',
    // The server default PHP, where a cron job switched off plain `php`.
    'php_default' => 'predeterminado',
    'adopted_left_unexpected_path' => 'su ubicación registrada no es una que el panel quitaría',
    'adopted_left_other_site' => 'pertenece a otro sitio o al servidor',
    'adopted_left_not_this_site' => 'ya no sirve solo a este sitio',
    'adopted_left_unprovable_config' => 'hace algo más que servir este sitio de una forma que el panel no puede comprobar: un bloque sin nombre, el servidor predeterminado, un include o un alias fuera del sitio',
    'adopted_left_not_regular' => 'no es un archivo normal',
    'adopted_left_foreign_link' => 'el servidor web lo carga mediante un enlace o una copia que el panel no esperaba',
    'adopted_left_unreadable' => 'no se pudo leer',
    'adopted_left_config_test_failed' => 'el servidor web rechazó su configuración sin él, así que se restauró',
    'adopted_left_remove_failed' => 'no se pudo quitar, así que se restauró todo',
    'adopted_left_restore_failed' => 'el servidor web rechazó su configuración sin él y no se pudo restaurar: revise ahora la configuración del servidor web',
    'adopted_left_ols_shared' => 'está registrado en la configuración compartida de OpenLiteSpeed, que el panel no edita',
    'adopted_left_worker_shared' => 'es un proceso en un archivo de programas compartido que el panel no puede demostrar que le pertenece, así que sigue en ejecución',
    'adopted_left_worker_systemd' => 'es un servicio de systemd que el panel no puede demostrar que le pertenece, así que sigue en ejecución',
    // The activity log's "what changed" line (2026-10-28).
    'on' => 'activado',
    'off' => 'desactivado',
    'with_changes' => ':sentence: :changes',
    'unchanged' => ':sentence (sin cambios)',
    'change' => ':field :from → :to',
    'change_secret' => ':field cambiada',
    'change_secret_removed' => ':field eliminada',
];
