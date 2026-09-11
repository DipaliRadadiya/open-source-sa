<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'このサーバーのパッケージソースに :version のパッケージがありません。',
        'apt_lock' => '別のパッケージ操作が実行中です。しばらくしてからもう一度お試しください。',
        'network' => 'パッケージリポジトリに接続できませんでした。サーバーのネットワーク接続を確認してください。',
        'no_space' => 'サーバーのディスク容量が不足しています。',
        'worker' => 'インストールが予期せず停止しました。タイムアウトの可能性があります — もう一度お試しください。',
        'unknown' => 'インストールに失敗しました。以下の参照番号をサポートにお伝えください。',
        'dpkg_broken' => 'ほかのインストールを行う前に、このサーバーのパッケージデータベースを修復する必要があります。',
        'port_in_use_by_mysql' => 'MySQL がすでにインストールされ、このポートを使用しています。先に削除するか、そのまま使い続けてください。',
        'port_in_use_by_mariadb' => 'MariaDB がすでにインストールされ、このポートを使用しています。先に削除するか、そのまま使い続けてください。',
        'root_unreachable' => 'インストールはされましたが、パネルからログインできませんでした。管理者ログインが既定から変更されているため、続けるにはその情報が必要です。',
        'cluster_missing' => 'PostgreSQL はインストールされていますが、このサーバーにクラスタがありません。削除されたか、セットアップが完了しなかった可能性があります。',
        'grant_failed' => 'インストールはされましたが、パネル自身のアカウントを作成できませんでした。',
        'repository_failed' => 'MongoDB のパッケージリポジトリを追加できませんでした。サーバーから repo.mongodb.org に接続できるか確認してください。',
        'unreachable' => 'インストールされましたが応答しません。下記の参照番号をサポートにお伝えください。',
        'auth_required' => 'ここにはすでに MongoDB があり、パネルが持っていないサインインを要求します。接続設定に認証情報を追加して再試行してください。',
        'auth_config_present' => 'MongoDB はインストール済みで、設定にすでに security セクションがあります。パネルはそれに触れていません — そこで authorization を有効にしてから再試行してください。',
        'auth_failed' => 'インストールされましたが認証を有効にできませんでした。下記の参照番号をサポートにお伝えください。',
    ],

    'uninstall_failed' => [
        'failed' => 'PHP :version を削除できませんでした。以下の参照番号をサポートにお伝えください。',
        'worker' => 'PHP :version の削除が予期せず停止しました。タイムアウトの可能性があります — もう一度お試しください。',
        'unknown' => 'PHP :version を削除できませんでした。以下の参照番号をサポートにお伝えください。',
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

    'fail2ban_install_failed' => [
        'package_not_found' => 'fail2ban のパッケージが見つかりません。サーバーのパッケージソースが設定され、到達可能かを確認してください。',
        'apt_lock' => '別のパッケージ操作が実行中です。少し待ってからやり直してください。',
        'network' => 'パッケージリポジトリに接続できませんでした。サーバーのネットワーク接続を確認してください。',
        'no_space' => 'サーバーのディスク容量が不足しています。',
        'worker' => 'インストールが予期せず停止しました。タイムアウトの可能性があります — もう一度お試しください。',
        'unknown' => 'fail2ban のインストールに失敗しました。下記の参照番号をサポートにお伝えください。',
    ],


    /*
    | Per-runtime overrides, consulted before the shared groups above.
    |
    | `install_failed` is shared by PHP, Node, database engines and
    | fail2ban. It used to be worded for PHP alone, so a failed MongoDB
    | install told the user to check the PHP repository. Only the reasons
    | that genuinely differ per runtime belong here; everything else
    | still falls through.
    */

    'php_install_failed' => [
        'package_not_found' => ':version のパッケージがありません。PHP リポジトリが設定され、到達可能か確認してください。',
    ],

    'node_install_failed' => [
        'package_not_found' => 'Node :version が見つかりませんでした。バージョン番号を確認するか、一覧から選んでください。',
    ],

    'database_install_failed' => [
        'package_not_found' => 'このサーバーに :version のパッケージがありません。そのパッケージリポジトリが設定され、到達可能か確認してください。',
        // The repository was added and its index fetched successfully;
        // the engine simply has no build for this Ubuntu release.
        'os_unsupported' => ':version はまだ :os 向けのパッケージを公開していません。このサーバーに問題はありません。パネルは :os に対応していますが、:version がまだ対応ビルドを出していないためです。別のデータベースエンジンを使うか、公開後に再度お試しください。',
    ],

    // Used for :os when /etc/os-release cannot be read.
    'this_server' => 'このサーバーの OS',
];
