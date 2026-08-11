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
        'title' => 'アクセスなし',
        'description' => 'このユーザーには表示されません。メニュー項目自体が現れません。',
    ],
    'view' => [
        'title' => '閲覧のみ',
        'description' => '画面を開いてすべて閲覧できますが、変更はできません。',
    ],
    'manage' => [
        'title' => '閲覧と編集',
        'description' => '画面を開いて変更できます — 作成・編集・削除。',
    ],
];
