<?php

return [
    'status' => [
        'pending' => 'Esperando para iniciar',
        'running' => 'Actualizando',
        'completed' => 'Actualizada',
        'failed' => 'Fallida',
    ],
    'reasons' => [
        'unsupported' => 'Este tipo de aplicación no se puede actualizar desde el panel.',
        'version_unchanged' => 'La actualización se ejecutó, pero la aplicación sigue indicando su versión anterior.',
        'step_failed' => 'Falló un paso de la actualización. La referencia apunta al registro del servidor.',
        'failed' => 'La actualización falló de forma inesperada. La referencia apunta al registro del servidor.',
        'worker' => 'La actualización se detuvo antes de terminar (el proceso en segundo plano terminó o agotó el tiempo).',
        'abandoned' => 'La actualización nunca empezó y se canceló.',
        'release_not_found' => 'No se encontró la nueva versión en la página de descargas del proyecto.',
        'download_unreachable' => 'No se pudo descargar la versión. Comprueba el acceso a internet del servidor y vuelve a intentarlo.',
        'out_of_memory' => 'El servidor se quedó sin memoria durante la actualización.',
        'composer_platform' => 'La versión de PHP o las extensiones del sitio no cumplen los requisitos de la nueva versión.',
        'no_build_tools' => 'Faltan herramientas de compilación en el servidor. Instálalas y vuelve a intentarlo.',
    ],
    'errors' => [
        'running' => 'Ya hay una actualización en curso para esta aplicación.',
        'unsupported' => 'Este tipo de aplicación no se puede actualizar desde el panel.',
        'not_active' => 'Solo se puede actualizar una aplicación activa y habilitada.',
        'version_unknown' => 'No se pudo leer la versión instalada desde los archivos de la aplicación.',
        'lookup_failed' => 'No se pudieron comprobar las versiones disponibles en este momento. Inténtalo más tarde.',
        'up_to_date' => 'La versión :version ya es la más reciente de esta versión principal.',
    ],
];
