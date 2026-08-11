<?php

/*
 * The three states a role's grant on one permission can be in.
 *
 * The pivot stores two booleans (`view`, `manage`) but only three of their
 * four combinations are reachable — `PermissionResolver` writes `view` true
 * whenever `manage` is, so "write without read" cannot exist. Naming the
 * three states here keeps the role form from inventing its own labels, which
 * is how a permission screen ends up English in every locale.
 */

return [
    'none' => [
        'title' => 'Kein Zugriff',
        'description' => 'Für diesen Benutzer ausgeblendet. Der Menüpunkt erscheint gar nicht.',
    ],
    'view' => [
        'title' => 'Nur Lesen',
        'description' => 'Kann die Seite öffnen und alles sehen, aber nichts ändern.',
    ],
    'manage' => [
        'title' => 'Lesen und Schreiben',
        'description' => 'Kann die Seite öffnen und Änderungen vornehmen – anlegen, bearbeiten und löschen.',
    ],
];
