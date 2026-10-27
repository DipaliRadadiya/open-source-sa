<?php

return [
    'none' => 'keine',
    'yes' => 'ja',
    'no' => 'nein',
    // The server default PHP, where a cron job switched off plain `php`.
    'php_default' => 'Standard',
    'adopted_left_unexpected_path' => 'der gespeicherte Ort ist keiner, den das Panel entfernen würde',
    'adopted_left_other_site' => 'es gehört zu einer anderen Website oder zum Server',
    'adopted_left_not_this_site' => 'es bedient nicht mehr nur diese Website',
    'adopted_left_unprovable_config' => 'es tut mehr, als diese Website zu bedienen, auf eine Weise, die das Panel nicht prüfen kann – ein Block ohne Namen, der Standardserver, ein Include oder ein Alias außerhalb der Website',
    'adopted_left_not_regular' => 'es ist keine normale Datei',
    'adopted_left_foreign_link' => 'der Webserver lädt es über einen Link oder eine Kopie, die das Panel nicht erwartet hat',
    'adopted_left_unreadable' => 'es konnte nicht gelesen werden',
    'adopted_left_config_test_failed' => 'der Webserver hat seine Konfiguration ohne es abgelehnt, daher wurde es wiederhergestellt',
    'adopted_left_remove_failed' => 'es konnte nicht entfernt werden, daher wurde alles wiederhergestellt',
    'adopted_left_restore_failed' => 'der Webserver hat seine Konfiguration ohne es abgelehnt und die Wiederherstellung ist fehlgeschlagen – prüfen Sie jetzt die Webserver-Konfiguration',
    'adopted_left_ols_shared' => 'es ist in der gemeinsamen OpenLiteSpeed-Konfiguration eingetragen, die das Panel nicht bearbeitet',
    'adopted_left_worker_shared' => 'es ist ein Worker in einer gemeinsamen Programmdatei, deren Besitz das Panel nicht nachweisen kann, daher läuft er weiter',
    'adopted_left_worker_systemd' => 'es ist ein systemd-Dienst, dessen Besitz das Panel nicht nachweisen kann, daher läuft er weiter',
];
