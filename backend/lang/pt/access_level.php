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
        'title' => 'Sem acesso',
        'description' => 'Oculto para este utilizador. O item de menu não aparece.',
    ],
    'view' => [
        'title' => 'Apenas leitura',
        'description' => 'Pode abrir o ecrã e ver tudo, mas não pode alterar nada.',
    ],
    'manage' => [
        'title' => 'Leitura e escrita',
        'description' => 'Pode abrir o ecrã e fazer alterações — criar, editar e eliminar.',
    ],
];
