<?php

return [
    'apt_cache' => ['label' => 'Caché de paquetes', 'description' => 'Archivos .deb descargados que ya no se necesitan.'],
    'apt_orphans' => ['label' => 'Paquetes sin usar', 'description' => 'Paquetes instalados automáticamente y kernels antiguos que ya no se necesitan.'],
    'journal' => ['label' => 'Journal del sistema', 'description' => 'Entradas del journal de systemd más antiguas que el período de retención.'],
    'rotated_logs' => ['label' => 'Registros rotados', 'description' => 'Archivos de registro comprimidos y rotados antiguos en /var/log.'],
    'service_logs' => ['label' => 'Registros de servicios', 'description' => 'Vacía los archivos de registro actuales de los servicios en ejecución (se conservan, no se eliminan).'],
    'tmp' => ['label' => 'Archivos temporales', 'description' => 'Archivos antiguos en /tmp y /var/tmp.'],
];
