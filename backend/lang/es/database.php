<?php

return [

    'install_steps' => [
        'queued' => 'En cola',
        'checking_conflicts' => 'Comprobando motores de base de datos en conflicto',
        'preparing_repository' => 'Preparando el repositorio de paquetes',
        'waiting_for_package_manager' => 'Esperando a que termine otra operación de paquetes',
        'updating_package_index' => 'Actualizando el índice de paquetes',
        'preparing' => 'Preparando paquetes',
        'downloading' => 'Descargando paquetes',
        'unpacking' => 'Desempaquetando paquetes',
        'configuring' => 'Configurando paquetes',
        'starting_service' => 'Iniciando el servicio de base de datos',
        'verifying_cluster' => 'Verificando el clúster de base de datos',
        'verifying_connection' => 'Verificando la conexión con la base de datos',
        'creating_panel_account' => 'Creando la cuenta de base de datos del panel',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'El volcado de la base de datos falló. Cite la referencia siguiente al soporte.',
        'database_missing' => 'La base de datos se eliminó antes de que la exportación pudiera ejecutarse.',
        'worker' => 'La exportación se detuvo inesperadamente. Puede haber expirado — inténtelo de nuevo.',
        'unknown' => 'La exportación falló. Cite la referencia siguiente al soporte.',
    ],

    'import_failed' => [
        'unknown' => 'La importación falló. Indica a soporte la referencia de abajo.',
        'database_missing' => 'La base de datos se eliminó antes de que pudiera ejecutarse la importación.',
        'no_account' => 'Esta base de datos no tiene un usuario con el que ejecutar la importación: uno cuya contraseña conozca el panel y que se conecte desde este servidor.',
        'wrong_format' => 'Este archivo no es un volcado que esta base de datos pueda importar. MySQL y MariaDB aceptan .sql o .sql.gz; PostgreSQL acepta .sql, .sql.gz o un archivo personalizado de pg_dump; MongoDB acepta un archivo de mongodump con --archive.',
        'client_command' => 'La línea :line del archivo contiene un comando de psql (un comando con barra invertida como \\!). Esos comandos pueden ejecutar programas en el servidor, así que el archivo se rechazó. Vuelve a exportar la base de datos como SQL plano con pg_dump.',
        'truncated' => 'El archivo termina a mitad de sus datos, así que está incompleto. Vuelve a exportarlo.',
        'unreadable' => 'No se pudo leer el archivo como un archivo de PostgreSQL.',
        'backup_failed' => 'La copia de seguridad previa a la importación falló, así que no se importó nada.',
        'load_failed' => 'No se pudo cargar el archivo en la base de datos. Indica a soporte la referencia de abajo.',
        'worker' => 'La importación se detuvo inesperadamente. Puede que haya superado el tiempo límite.',
        'abandoned' => 'La subida no se terminó en un día y se ha eliminado.',
        'restored' => 'La base de datos se restauró desde la copia de seguridad automática, así que no se perdió nada.',
        'untouched' => 'La base de datos no se modificó.',
        'not_restored' => 'La copia de seguridad automática no se pudo restaurar por sí sola. Está en la lista de exportaciones: descárgala e impórtala para recuperar la base de datos.',
        'no_copy' => 'No se tomó una copia de seguridad (se desactivó para esta importación), así que no había nada que restaurar: la base de datos puede estar vacía o cargada a medias.',
    ],

];
