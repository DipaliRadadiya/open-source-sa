<?php

return [
    'busy' => 'El servidor está ocupado con otra tarea del sistema (puede haber una instalación o actualización de paquetes en curso). No se cambió nada: inténtalo de nuevo en un momento.',
    'databases_not_managed' => 'Este servidor no gestiona bases de datos. Ejecuta contenedores, y una aplicación que necesite una base de datos trae la suya.',
    'operation_timed_out' => 'El servidor tardó demasiado en esta operación y la detuvo. No hay nada averiado: el trabajo era demasiado grande para el tiempo que permite una sola petición. Todo lo que supere este tamaño debe hacerse con una copia de seguridad, que se ejecuta en segundo plano y no tiene ese límite.',
    'stale_lock' => 'Un archivo de bloqueo sobrante está impidiendo toda la gestión de usuarios en este servidor. Nada lo está usando: lo dejó un comando interrumpido. Ejecuta `php artisan panel:doctor` para ver qué archivos eliminar.',
    'sudo_denied' => 'El permiso sudo de este servidor es más antiguo que el panel que ejecuta, así que el comando fue rechazado antes de ejecutarse. No se cambió nada y volver a intentarlo no servirá. Ejecuta `sudo php artisan panel:sudoers` en el servidor para reescribir el permiso y vuelve a intentarlo.',
    // Before an action that writes a lot to the disk (2026-10-29).
    'not_enough_disk_space' => 'No hay suficiente espacio libre en el disco del servidor: se necesitan unos :needed y solo hay :free disponibles. Libere espacio e inténtelo de nuevo.',
];
