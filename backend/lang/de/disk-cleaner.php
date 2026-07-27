<?php

return [
    'apt_cache' => ['label' => 'Paket-Cache', 'description' => 'Heruntergeladene .deb-Paketdateien, die nicht mehr benötigt werden.', 'note' => 'Entfernt nur zwischengespeicherte Downloads unter /var/cache/apt/archives — installierte Pakete funktionieren weiter.'],
    'apt_orphans' => ['label' => 'Ungenutzte Pakete', 'description' => 'Automatisch installierte Pakete und alte Kernel, die nicht mehr benötigt werden.', 'note' => 'Entfernt Pakete, von denen nichts mehr abhängt, und veraltete Kernel; der laufende Kernel bleibt erhalten.'],
    'journal' => ['label' => 'System-Journal', 'description' => 'systemd-Journal-Einträge, die älter als der Aufbewahrungszeitraum sind.', 'note' => 'Kürzt alte Journal-Historie über den Aufbewahrungszeitraum hinaus; aktuelle Einträge bleiben erhalten.'],
    'rotated_logs' => ['label' => 'Rotierte Protokolle', 'description' => 'Alte komprimierte und rotierte Protokollarchive unter /var/log.', 'note' => 'Löscht bereits rotierte Archive (.gz / .1 / .old) unter /var/log; aktuelle Protokolle bleiben unberührt.'],
    'service_logs' => ['label' => 'Dienstprotokolle', 'description' => 'Leert die aktuellen Protokolldateien laufender Dienste (werden geleert, nicht gelöscht).', 'note' => 'Leert die aktuellen Protokolldateien (auf 0 Byte gekürzt) — Dienste schreiben weiter hinein, nichts wird gelöscht.'],
    'tmp' => ['label' => 'Temporäre Dateien', 'description' => 'Alte Dateien in /tmp und /var/tmp.', 'note' => 'Löscht Dateien in /tmp und /var/tmp, die älter als der Aufbewahrungszeitraum sind.'],
];
