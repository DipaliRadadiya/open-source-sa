<?php

return [
    'steps' => [
        'dump_database' => 'Volcando la base de datos',
        'archive_files' => 'Creando el archivo',
        'upload_artifact' => 'Subiendo al almacenamiento',
        'verify_artifact' => 'Verificando la subida',
        'prune_old_backups' => 'Eliminando copias antiguas',
        'rollback' => 'Limpiando',
    ],
    'status' => [
        'pending' => 'En cola',
        'running' => 'Copiando',
        'verifying' => 'Verificando',
        'verified' => 'Completada',
        'failed' => 'Fallida',
    ],
    'type' => [
        'filesystem' => 'Archivos',
        'database' => 'Base de datos',
        'full' => 'Archivos y base de datos',
    ],
    'frequency' => [
        'manual' => 'Solo manual',
        'daily' => 'Diaria',
        'weekly' => 'Semanal',
        'monthly' => 'Mensual',
    ],
    'errors' => [
        'not_configured' => 'Las copias de seguridad aún no están configuradas para esta aplicación.',
        'already_running' => 'Ya hay una copia de seguridad en curso para esta aplicación.',
        'dump_database' => 'No se pudo volcar la base de datos, así que no se subió nada.',
        'archive_files' => 'No se pudo crear el archivo; normalmente el servidor se quedó sin espacio en disco.',
        'upload_artifact' => 'No se pudo subir el archivo. Comprueba que el destino de almacenamiento sigue aceptando escrituras.',
        'verify_artifact' => 'La subida no coincide con lo enviado, así que no se puede confiar en esta copia. No se eliminó ninguna copia antigua.',
        'unknown' => 'La copia de seguridad falló por un motivo desconocido.',
        'prune_old_backups' => 'No se pudieron eliminar las copias antiguas. La nueva copia está a salvo; el almacenamiento puede tener más copias de las configuradas.',
    ],
];
