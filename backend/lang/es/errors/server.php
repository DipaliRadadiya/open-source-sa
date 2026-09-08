<?php

return [
    'busy' => 'El servidor está ocupado con otra tarea del sistema (puede haber una instalación o actualización de paquetes en curso). No se cambió nada: inténtalo de nuevo en un momento.',
    'stale_lock' => 'Un archivo de bloqueo sobrante está impidiendo toda la gestión de usuarios en este servidor. Nada lo está usando: lo dejó un comando interrumpido. Ejecuta `php artisan panel:doctor` para ver qué archivos eliminar.',
    'sudo_denied' => 'El permiso sudo de este servidor es más antiguo que el panel que ejecuta, así que el comando fue rechazado antes de ejecutarse. No se cambió nada y volver a intentarlo no servirá. Ejecuta `sudo php artisan panel:sudoers` en el servidor para reescribir el permiso y vuelve a intentarlo.',
];
