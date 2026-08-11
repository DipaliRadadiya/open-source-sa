<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'Voller Shell-Zugriff (bash)',
        'description' => 'Die Standard-Linux-Shell. Der Benutzer kann sich per SSH anmelden und Befehle ausführen.',
    ],
    'sh' => [
        'title' => 'Einfache Shell (sh)',
        'description' => 'Eine minimale Shell. Anmeldung und Befehle sind möglich, mit weniger Komfort als bash.',
    ],
    'zsh' => [
        'title' => 'Voller Shell-Zugriff (zsh)',
        'description' => 'Wie bash, mit anderem Komfort. Der Benutzer kann sich anmelden und Befehle ausführen.',
    ],
    'nologin' => [
        'title' => 'Keine Anmeldung',
        'description' => 'Der Benutzer besitzt seine Dateien und betreibt die Website, kann sich aber nicht anmelden. Empfohlen für Websites ohne Shell-Bedarf.',
    ],
    'false' => [
        'title' => 'Keine Anmeldung (veraltet)',
        'description' => 'Die Anmeldung wird sofort abgelehnt. Gleiche Wirkung wie „Keine Anmeldung“; für Server beibehalten, die es bereits nutzen.',
    ],
];
