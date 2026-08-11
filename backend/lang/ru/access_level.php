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
        'title' => 'Нет доступа',
        'description' => 'Скрыто от этого пользователя. Пункт меню не отображается.',
    ],
    'view' => [
        'title' => 'Только чтение',
        'description' => 'Может открыть экран и всё видеть, но ничего не может изменить.',
    ],
    'manage' => [
        'title' => 'Чтение и запись',
        'description' => 'Может открыть экран и вносить изменения — создавать, редактировать и удалять.',
    ],
];
