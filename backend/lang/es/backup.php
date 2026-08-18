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
        'restore_unverified' => 'Esta copia nunca se verificó, así que no se puede restaurar.',
        'restore_no_application' => 'La aplicación de esta copia ya no existe.',
        'restore_confirm' => 'Escribe el dominio de la aplicación exactamente para confirmar la restauración.',
        'restore_already_running' => 'Ya hay una restauración en curso para esta aplicación.',
        'restore_no_database' => 'Esta copia no contiene ninguna base de datos.',
        'restore_no_files' => 'Esta copia no contiene archivos.',
        'download_no_artifact' => 'Esta copia de seguridad nunca terminó de subirse, así que no hay ningún archivo para descargar.',
        'download_no_destination' => 'El destino de almacenamiento al que se subió esta copia de seguridad ya no existe.',
        'download_missing' => 'El archivo ya no está en el destino de almacenamiento.',
        'not_configured' => 'Las copias de seguridad aún no están configuradas para esta aplicación.',
        'delete_running' => 'Esta copia aún se está ejecutando, así que todavía no se puede eliminar. Espere a que termine o falle.',
        'delete_artifact' => 'No se pudo eliminar el archivo del destino de almacenamiento, así que no se borró nada. Compruebe que el destino es accesible e inténtelo de nuevo.',
        'delete_target_running' => 'Todavía se está ejecutando una copia de esta aplicación. Espere a que termine antes de desactivar las copias.',
        'delete_target_has_backups' => 'Esta aplicación todavía tiene :count copia(s). Confirme que también deben eliminarse, o elimínelas primero.',
        'already_running' => 'Ya hay una copia de seguridad en curso para esta aplicación.',
        'dump_database' => 'No se pudo volcar la base de datos, así que no se subió nada.',
        'archive_files' => 'No se pudo crear el archivo; normalmente el servidor se quedó sin espacio en disco.',
        'upload_artifact' => 'No se pudo subir el archivo. Comprueba que el destino de almacenamiento sigue aceptando escrituras.',
        'verify_artifact' => 'La subida no coincide con lo enviado, así que no se puede confiar en esta copia. No se eliminó ninguna copia antigua.',
        'unknown' => 'La copia de seguridad falló por un motivo desconocido.',
        'prune_old_backups' => 'No se pudieron eliminar las copias antiguas. La nueva copia está a salvo; el almacenamiento puede tener más copias de las configuradas.',
    ],

    'restore_status' => [
        'pending' => 'En cola',
        'running' => 'Restaurando',
        'succeeded' => 'Restaurado',
        'failed' => 'La restauración falló',
    ],

    'restore_steps' => [
        'download_artifact' => 'Descargando la copia de seguridad',
        'verify_download' => 'Comprobando que la copia está intacta',
        'safety_backup' => 'Copiando primero el estado actual',
        'extract_archive' => 'Descomprimiendo la copia',
        'restore_database' => 'Restaurando la base de datos',
        'swap_files' => 'Colocando los archivos',
        'restart_process' => 'Iniciando la aplicación',
    ],

    'restore_errors' => [
        'download_artifact' => 'No se pudo descargar la copia. No se cambió nada en el servidor.',
        'verify_download' => 'La copia descargada está incompleta o dañada, así que no se usó. No se cambió nada en el servidor.',
        'safety_backup' => 'No se pudo copiar el estado actual, así que se detuvo la restauración. No se sobrescribió nada.',
        'extract_archive' => 'No se pudo descomprimir la copia. No se cambió nada en el servidor.',
        'restore_database' => 'No se pudo restaurar la base de datos. La copia de seguridad previa conserva el estado anterior.',
        'swap_files' => 'No se pudieron colocar los archivos. Se restauró el directorio anterior del sitio.',
        'restart_process' => 'Se restauraron los archivos y la base de datos, pero la aplicación no arrancó. Revisa sus registros.',
        'missing_backup' => 'La copia se eliminó antes de que pudiera empezar la restauración.',
        'crashed' => 'La restauración se detuvo inesperadamente. Revisa la copia de seguridad antes de reintentar.',
        'unknown' => 'La restauración falló por un motivo desconocido.',
    ],

    'cloning' => [
        'provisioning' => 'Creando el sitio',
        'copying_files' => 'Copiando archivos',
        'cloning_database' => 'Clonando la base de datos',
        'starting_process' => 'Iniciando la aplicación',
    ],

    'cloning_errors' => [
        'crashed' => 'La clonación se detuvo inesperadamente.',
    ],

    'schedule_time' => 'Hora programada',
];
