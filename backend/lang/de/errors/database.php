<?php

return [
    'operation_failed' => 'Der Datenbankvorgang ist auf dem Server fehlgeschlagen.',
    'export_already_running' => 'Ein Export dieser Datenbank läuft bereits. Warten Sie, bis er fertig ist, bevor Sie einen weiteren starten.',
    'collation_mismatch' => 'Die gewählte Kollation gehört nicht zum gewählten Zeichensatz.',
    'application_already_attached' => 'Mit :application ist bereits die Datenbank :database verknüpft. Heben Sie diese Verknüpfung zuerst auf, oder verknüpfen Sie diese Datenbank mit einer anderen Anwendung.',
    'engine_not_accepted' => ':application kann keine :engine-Datenbank verwenden. Akzeptiert wird :accepted.',
    'engine_not_installable' => 'Das Panel kann diese Datenbank-Engine noch nicht installieren. Installieren Sie sie selbst, das Panel erkennt sie dann.',
    'phpmyadmin_engine_not_supported' => 'phpMyAdmin unterstützt keine :engine-Datenbanken.',
    'phpmyadmin_not_deployed' => 'Keine phpMyAdmin-Site ist auf diesem Server installiert.',
    'phpmyadmin_no_users' => 'Erstellen Sie einen Datenbankbenutzer, bevor Sie auf phpMyAdmin zugreifen.',
    'remote_users_unsupported' => 'Fernzugriff ist für :engine nicht verfügbar – seine Konten sind nicht an einen Host gebunden. Verwende localhost.',
    'phpmyadmin_not_selectable' => 'Die ausgewählte Website ist keine aktive phpMyAdmin-Installation.',
    'phpmyadmin_user_not_found' => 'Der angegebene Datenbankbenutzer gehört nicht zu dieser Datenbank.',
    'phpmyadmin_not_isolated' => 'Diese phpMyAdmin-Site nutzt den serverweiten PHP-Pool, sodass ein Anmeldelink für jede andere Site lesbar wäre. Geben Sie ihr einen eigenen PHP-Pool, oder öffnen Sie phpMyAdmin und melden Sie sich mit den Datenbank-Zugangsdaten an.',
    'phpmyadmin_sso_unavailable' => 'Der Anmeldelink konnte auf der phpMyAdmin-Site nicht vorbereitet werden.',

];
