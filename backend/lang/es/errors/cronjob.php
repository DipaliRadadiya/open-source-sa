<?php

return [
    'sync_failed' => 'No se pudo aplicar la tarea cron en el servidor.',
    // One sentence per privileged step. They all used to share
    // `sync_failed`, so a full disk and a missing group read the same.
    'step' => [
        'log_dir' => 'No se pudo crear el directorio de registros de tareas cron. Compruebe que haya espacio libre en disco y que /var/log sea escribible.',
        'log_touch' => 'No se pudo crear el archivo de registro de la tarea cron. Normalmente el disco está lleno.',
        'log_chown' => 'No se pudo asignar el archivo de registro a la cuenta con la que se ejecuta la tarea. Compruebe que esa cuenta siga existiendo.',
        'log_chmod' => 'No se pudieron establecer los permisos del archivo de registro.',
        'rotation' => 'No se pudo instalar la política de rotación de registros, así que la tarea no se programó: su salida crecería sin límite.',
        'write' => 'No se pudo escribir el archivo cron. Compruebe que haya espacio libre en disco.',
        'chmod' => 'No se pudieron establecer los permisos del archivo cron. Cron ignora un archivo en el que no confía, así que la tarea no se programó.',
        'remove' => 'No se pudo eliminar el archivo cron, así que la tarea sigue programada en el servidor.',
        'remove_stale' => 'No se pudo eliminar el archivo cron antiguo tras el cambio de nombre. No se cambió nada, así que la tarea no queda programada dos veces.',
        'detach_source' => 'No se pudo eliminar el archivo cron original del que se importó esta tarea. No se cambió nada, así que el comando no se ejecuta dos veces.',
    ],
    'invalid_expression' => 'La programación no es una expresión cron válida.',
    'invalid_user' => 'El usuario seleccionado no existe en el servidor.',
    'unresolved_placeholder' => 'El comando aún contiene el marcador {path}; reemplázalo por el directorio de la aplicación.',
    'no_newline' => 'Este valor no puede contener saltos de línea.',
    'reserved_name' => 'Este nombre está reservado y no se puede usar.',
];
