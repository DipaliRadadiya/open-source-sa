<?php

/*
 * Human labels for the login shells a system user can be given. The stored
 * value is the binary path `usermod -s` needs; these are what the panel shows
 * instead, because "/usr/sbin/nologin" does not tell a non-sysadmin that they
 * are turning login off.
 */

return [
    'bash' => [
        'title' => 'フルシェルアクセス (bash)',
        'description' => '標準的な Linux シェル。SSH でログインしてコマンドを実行できます。',
    ],
    'sh' => [
        'title' => '基本シェル (sh)',
        'description' => '最小限のシェル。ログインしてコマンドを実行できますが、bash より機能は限られます。',
    ],
    'zsh' => [
        'title' => 'フルシェルアクセス (zsh)',
        'description' => 'bash と同様で、便利機能が異なります。ログインしてコマンドを実行できます。',
    ],
    'nologin' => [
        'title' => 'ログイン不可',
        'description' => 'ファイルを所有しサイトを実行しますが、ログインはできません。シェルアクセスが不要なサイトに推奨します。',
    ],
    'false' => [
        'title' => 'ログイン不可 (旧式)',
        'description' => 'ログインは即座に拒否されます。「ログイン不可」と同じ効果で、既に使用中のサーバー向けに残しています。',
    ],
];
