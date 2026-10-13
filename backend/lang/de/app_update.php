<?php

return [
    'status' => [
        'pending' => 'Wartet auf Start',
        'running' => 'Wird aktualisiert',
        'completed' => 'Aktualisiert',
        'failed' => 'Fehlgeschlagen',
    ],
    'reasons' => [
        'unsupported' => 'Dieser Anwendungstyp kann nicht über das Panel aktualisiert werden.',
        'version_unchanged' => 'Das Update lief, aber die Anwendung meldet weiterhin ihre alte Version.',
        'step_failed' => 'Ein Schritt des Updates ist fehlgeschlagen. Die Referenz verweist auf das Serverprotokoll.',
        'failed' => 'Das Update ist unerwartet fehlgeschlagen. Die Referenz verweist auf das Serverprotokoll.',
        'worker' => 'Das Update wurde vor dem Ende abgebrochen (der Hintergrundprozess endete oder lief in ein Zeitlimit).',
        'abandoned' => 'Das Update wurde nie gestartet und abgebrochen.',
        'release_not_found' => 'Die neue Version wurde auf der Download-Seite des Projekts nicht gefunden.',
        'download_unreachable' => 'Die Version konnte nicht heruntergeladen werden. Prüfen Sie den Internetzugang des Servers und versuchen Sie es erneut.',
        'out_of_memory' => 'Dem Server ist während des Updates der Arbeitsspeicher ausgegangen.',
        'composer_platform' => 'PHP-Version oder Erweiterungen der Website erfüllen die Anforderungen der neuen Version nicht.',
        'no_build_tools' => 'Auf dem Server fehlen Build-Tools. Installieren Sie sie und versuchen Sie es erneut.',
    ],
    'errors' => [
        'running' => 'Für diese Anwendung läuft bereits ein Update.',
        'unsupported' => 'Dieser Anwendungstyp kann nicht über das Panel aktualisiert werden.',
        'not_active' => 'Nur eine aktive, aktivierte Anwendung kann aktualisiert werden.',
        'version_unknown' => 'Die installierte Version konnte nicht aus den Dateien der Anwendung gelesen werden.',
        'lookup_failed' => 'Die verfügbaren Versionen konnten gerade nicht geprüft werden. Versuchen Sie es später erneut.',
        'up_to_date' => 'Version :version ist bereits die neueste Version dieser Hauptversion.',
    ],
];
