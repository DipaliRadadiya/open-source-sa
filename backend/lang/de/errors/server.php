<?php

return [
    'busy' => 'Der Server ist mit einer anderen Systemaufgabe beschäftigt (möglicherweise läuft eine Paketinstallation oder ein Update). Es wurde nichts geändert — versuchen Sie es gleich noch einmal.',
    'stale_lock' => 'Eine übrig gebliebene Sperrdatei blockiert die gesamte Benutzerverwaltung auf diesem Server. Sie wird von nichts benutzt — ein abgebrochener Befehl hat sie hinterlassen. Führen Sie `php artisan panel:doctor` aus, um die zu entfernenden Dateien zu sehen.',
    'sudo_denied' => 'Die sudo-Berechtigung dieses Servers ist älter als das darauf laufende Panel, deshalb wurde der Befehl abgelehnt, bevor er ausgeführt wurde. Es wurde nichts geändert, und ein erneuter Versuch hilft nicht. Führen Sie auf dem Server `sudo php artisan panel:sudoers` aus, um die Berechtigung neu zu schreiben, und versuchen Sie es dann noch einmal.',
];
