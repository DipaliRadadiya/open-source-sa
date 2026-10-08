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

        'not_downloadable' => 'Este registro no es un archivo: la salida de un contenedor la guarda Docker. Léalo en pantalla.',
        'unknown_source' => 'Ese registro no existe para esta aplicación.',
        'clear_shared' => 'En OpenLiteSpeed las detecciones del cortafuegos forman parte del registro de acceso del sitio. Vacíe el registro de acceso en su lugar.',
    ],
];
