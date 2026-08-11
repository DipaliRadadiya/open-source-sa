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
        'title' => 'Sin acceso',
        'description' => 'Oculto para este usuario. El elemento del menú no aparece.',
    ],
    'view' => [
        'title' => 'Solo lectura',
        'description' => 'Puede abrir la pantalla y ver todo, pero no puede cambiar nada.',
    ],
    'manage' => [
        'title' => 'Lectura y escritura',
        'description' => 'Puede abrir la pantalla y hacer cambios: crear, editar y eliminar.',
    ],
];
