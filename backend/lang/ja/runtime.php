<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => ':version のパッケージがありません。PHP リポジトリが設定され、到達可能か確認してください。',
        'apt_lock' => '別のパッケージ操作が実行中です。しばらくしてからもう一度お試しください。',
        'network' => 'パッケージリポジトリに接続できませんでした。サーバーのネットワーク接続を確認してください。',
        'no_space' => 'サーバーのディスク容量が不足しています。',
        'worker' => 'インストールが予期せず停止しました。タイムアウトの可能性があります — もう一度お試しください。',
        'unknown' => 'インストールに失敗しました。以下の参照番号をサポートにお伝えください。',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'PHP :version 用の :extension パッケージがありません。このバージョンには存在しない可能性があります。',
        'apt_lock' => '別のパッケージ操作が実行中です。しばらくしてからもう一度お試しください。',
        'network' => 'パッケージリポジトリに接続できませんでした。サーバーのネットワーク接続を確認してください。',
        'no_space' => 'サーバーのディスク容量が不足しています。',
        'worker' => ':extension のインストールが予期せず停止しました。タイムアウトの可能性があります — もう一度お試しください。',
        'unknown' => ':extension のインストールに失敗しました。以下の参照番号をサポートにお伝えください。',
        'enable_failed' => ':extension はインストールされましたが有効化できませんでした。もう一度切り替えてください。',
    ],

];
