<?php

return [
    'not_found' => 'Este proceso ya no se está ejecutando.',
    'protected' => 'Este proceso pertenece a un servicio protegido y no puede detenerse aquí.',
    'database' => 'Este es un servidor de bases de datos. Detenerlo dejaría sin conexión la base de datos de todos los sitios, por lo que no se puede detener aquí. Para reiniciarlo, usa la pantalla Servicios.',
    'kernel_thread' => 'Los subprocesos del kernel no se pueden detener.',
    'self' => 'El panel no puede detener su propio proceso.',
    'kill_failed' => 'No se pudo detener el proceso.',
    'still_running' => 'El proceso sigue en ejecución. Puede que aún se esté cerrando o que esté ignorando la solicitud. Usa Forzar detención para terminarlo ahora.',
    'still_running_after_kill' => 'El proceso sigue en ejecución después de Forzar detención. Probablemente está bloqueado esperando un disco o un recurso de red, y ninguna señal puede terminarlo hasta que esa espera acabe.',
];
