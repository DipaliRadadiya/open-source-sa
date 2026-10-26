<?php

return [
    'apt_cache' => ['label' => 'Caché de paquetes', 'description' => 'Archivos de paquetes .deb descargados.', 'note' => '«Eliminar todo» vacía la caché; un periodo de conservación elimina solo las descargas más antiguas. Los paquetes instalados siguen funcionando.'],
    'apt_orphans' => ['label' => 'Paquetes sin usar', 'description' => 'Paquetes instalados automáticamente y kernels antiguos que ya no se necesitan.', 'note' => 'Elimina paquetes de los que ya nada depende y kernels obsoletos; el kernel en uso se conserva.'],
    'journal' => ['label' => 'Journal del sistema', 'description' => 'Historial del journal de systemd.', 'note' => 'Elimina las entradas del journal más antiguas que lo elegido; «Eliminar todo» las elimina todas.'],
    'rotated_logs' => ['label' => 'Registros rotados', 'description' => 'Archivos de registros antiguos comprimidos y rotados en /var/log.', 'note' => 'Elimina los archivos rotados (.gz / .1 / .old) más antiguos que lo elegido, o todos con «Eliminar todo». Los registros actuales no se tocan.'],
    'service_logs' => ['label' => 'Registros de servicios', 'description' => 'Archivos de registro actuales de los servicios en ejecución.', 'note' => '«Eliminar todo» los vacía (los archivos se conservan y los servicios siguen escribiendo). Un periodo de conservación vacía solo los registros en los que no se ha escrito en ese tiempo.'],
    'site_logs' => ['label' => 'Registros de aplicaciones', 'description' => 'Registros de acceso y de errores de cada aplicación alojada, con sus copias rotadas.', 'note' => 'Elimina las copias rotadas más antiguas que lo elegido y vacía los registros en los que no se ha escrito en ese tiempo. «Eliminar todo» vacía cada registro y elimina cada copia: se pierde el historial de visitas y errores.'],
    'tmp' => ['label' => 'Archivos temporales', 'description' => 'Archivos en /tmp y /var/tmp.', 'note' => 'Elimina los archivos más antiguos que lo elegido. Incluso «Eliminar todo» conserva los archivos escritos en la última hora, para no romper instalaciones ni subidas en curso.'],
    // The keep choice in words, for the activity log.
    'keep_days' => ['default' => 'retención estándar', 'delete_all' => 'se eliminó todo', 'keep' => 'se conservó :days día|se conservaron :days días'],
];
