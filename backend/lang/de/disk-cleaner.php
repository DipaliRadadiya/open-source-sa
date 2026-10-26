<?php

return [
    'apt_cache' => ['label' => 'Paket-Cache', 'description' => 'Heruntergeladene .deb-Paketdateien.', 'note' => '„Alles löschen“ leert den Cache; eine Aufbewahrungswahl entfernt nur ältere Downloads. Installierte Pakete funktionieren weiter.'],
    'apt_orphans' => ['label' => 'Ungenutzte Pakete', 'description' => 'Automatisch installierte Pakete und alte Kernel, die nicht mehr benötigt werden.', 'note' => 'Entfernt Pakete, von denen nichts mehr abhängt, und veraltete Kernel; der laufende Kernel bleibt erhalten.'],
    'journal' => ['label' => 'System-Journal', 'description' => 'Verlauf des systemd-Journals.', 'note' => 'Entfernt Journal-Einträge, die älter als die gewählte Aufbewahrung sind; „Alles löschen“ entfernt alle.'],
    'rotated_logs' => ['label' => 'Rotierte Protokolle', 'description' => 'Alte komprimierte und rotierte Log-Archive unter /var/log.', 'note' => 'Löscht rotierte Archive (.gz / .1 / .old), die älter als die gewählte Aufbewahrung sind, oder mit „Alles löschen“ alle. Aktuelle Logs bleiben unberührt.'],
    'service_logs' => ['label' => 'Dienstprotokolle', 'description' => 'Aktuelle Log-Dateien laufender Dienste.', 'note' => '„Alles löschen“ leert sie (die Dateien bleiben, die Dienste schreiben weiter). Eine Aufbewahrungswahl leert nur Logs, in die in diesem Zeitraum nichts geschrieben wurde.'],
    'site_logs' => ['label' => 'Site-Logs', 'description' => 'Zugriffs- und Fehler-Logs jeder gehosteten Anwendung samt ihren rotierten Kopien.', 'note' => 'Löscht rotierte Kopien, die älter als die gewählte Aufbewahrung sind, und leert Logs, in die in diesem Zeitraum nichts geschrieben wurde. „Alles löschen“ leert jedes Log und löscht jede Kopie: Besucher- und Fehlerverlauf sind weg.'],
    'tmp' => ['label' => 'Temporäre Dateien', 'description' => 'Dateien in /tmp und /var/tmp.', 'note' => 'Löscht Dateien, die älter als die gewählte Aufbewahrung sind. Auch „Alles löschen“ behält Dateien, die in der letzten Stunde geschrieben wurden, damit laufende Installationen und Uploads nicht abbrechen.'],
    // The keep choice in words, for the activity log.
    'keep_days' => ['default' => 'Standardaufbewahrung', 'delete_all' => 'alles gelöscht', 'keep' => ':days Tag behalten|:days Tage behalten'],
];
