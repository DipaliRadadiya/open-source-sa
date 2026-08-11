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
        'title' => 'Aucun accès',
        'description' => 'Masqué pour cet utilisateur. L\'élément de menu n\'apparaît pas.',
    ],
    'view' => [
        'title' => 'Lecture seule',
        'description' => 'Peut ouvrir l\'écran et tout voir, mais ne peut rien modifier.',
    ],
    'manage' => [
        'title' => 'Lecture et écriture',
        'description' => 'Peut ouvrir l\'écran et apporter des modifications : créer, modifier et supprimer.',
    ],
];
