<?php

return [
    'sources' => [
        'container' => 'Contenedor',
        'access' => 'Registro de acceso',
        'error' => 'Registro de errores',
        'application' => 'Salida de la aplicación',
        'application_error' => 'Errores de la aplicación',
        'waf_detect' => 'Detecciones del firewall',
    ],

    'errors' => [
        'range_unsupported' => 'Un número de línea solo se aplica a un archivo de registro. El diario del sistema y la salida del contenedor no tienen números de línea fijos; lea las últimas líneas en su lugar.',
        'fields_unsupported' => 'Los filtros de IP, estado, método y URL solo se aplican al registro de acceso. Use la búsqueda de texto para este registro.',

        'not_downloadable' => 'Este registro no es un archivo: la salida de un contenedor la guarda Docker. Léalo en pantalla.',
        'unknown_source' => 'Ese registro no existe para esta aplicación.',
        'clear_shared' => 'En OpenLiteSpeed las detecciones del cortafuegos forman parte del registro de acceso de la aplicación. Vacíe el registro de acceso en su lugar.',
    ],
];
