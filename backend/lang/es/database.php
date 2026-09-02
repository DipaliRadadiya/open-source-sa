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

];
