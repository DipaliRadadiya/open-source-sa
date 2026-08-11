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
        'title' => 'No access',
        'description' => 'Hidden from this user. The menu item does not appear at all.',
    ],
    'view' => [
        'title' => 'Read only',
        'description' => 'Can open the screen and see everything on it, but cannot change anything.',
    ],
    'manage' => [
        'title' => 'Read & write',
        'description' => 'Can open the screen and make changes — create, edit and delete.',
    ],
];
