<?php

return [
    'apt_cache' => ['label' => 'Paket-Cache', 'description' => 'Heruntergeladene .deb-Paketdateien, die nicht mehr benötigt werden.'],
    'apt_orphans' => ['label' => 'Ungenutzte Pakete', 'description' => 'Automatisch installierte Pakete und alte Kernel, die nicht mehr benötigt werden.'],
    'journal' => ['label' => 'System-Journal', 'description' => 'systemd-Journal-Einträge, die älter als der Aufbewahrungszeitraum sind.'],
    'rotated_logs' => ['label' => 'Rotierte Protokolle', 'description' => 'Alte komprimierte und rotierte Protokollarchive unter /var/log.'],
    'service_logs' => ['label' => 'Dienstprotokolle', 'description' => 'Leert die aktuellen Protokolldateien laufender Dienste (werden geleert, nicht gelöscht).'],
    'tmp' => ['label' => 'Temporäre Dateien', 'description' => 'Alte Dateien in /tmp und /var/tmp.'],
];
