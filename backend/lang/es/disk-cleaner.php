<?php

return [
    'apt_cache' => ['label' => 'Caché de paquetes', 'description' => 'Archivos .deb descargados que ya no se necesitan.', 'note' => 'Elimina solo las descargas en caché de /var/cache/apt/archives; los paquetes instalados siguen funcionando.'],
    'apt_orphans' => ['label' => 'Paquetes sin usar', 'description' => 'Paquetes instalados automáticamente y kernels antiguos que ya no se necesitan.', 'note' => 'Elimina paquetes de los que ya nada depende y kernels obsoletos; el kernel en uso se conserva.'],
    'journal' => ['label' => 'Journal del sistema', 'description' => 'Entradas del journal de systemd más antiguas que el período de retención.', 'note' => 'Recorta el historial antiguo del journal más allá del período de retención; las entradas recientes se conservan.'],
    'rotated_logs' => ['label' => 'Registros rotados', 'description' => 'Archivos de registro comprimidos y rotados antiguos en /var/log.', 'note' => 'Elimina los archivos ya rotados (.gz / .1 / .old) en /var/log; los registros actuales no se tocan.'],
    'service_logs' => ['label' => 'Registros de servicios', 'description' => 'Vacía los archivos de registro actuales de los servicios en ejecución (se conservan, no se eliminan).', 'note' => 'Vacía los archivos de registro actuales (truncados a 0 bytes); los servicios siguen escribiendo en ellos, nada se elimina.'],
    'tmp' => ['label' => 'Archivos temporales', 'description' => 'Archivos antiguos en /tmp y /var/tmp.', 'note' => 'Elimina archivos en /tmp y /var/tmp más antiguos que el período de retención.'],
];
