<?php

return [
    'not_installed' => 'このサーバーに fail2ban はインストールされていません。',
    'already_installed' => 'fail2ban は既にインストールされています。',
    'already_installing' => 'fail2ban は既にインストール中です。そのインストールが終わるまでお待ちください。',
    'not_running' => 'fail2ban はインストールされていますが実行されていません。',
    'foreign_jail_local' => ':path はパネルが書き込んだファイルではないため、上書きしません。fail2ban は手動または別のパネルによってそこで設定済みです。パネルに管理させたい場合は、そのファイルを移動または削除してください。',
    'jail_not_active' => ':jail の jail は有効ではありません。',
    'not_banned' => 'その IP アドレスは現在ブロックされていません。',
    'lockout_risk' => 'SSH の jail を有効にすると、このサーバーにアクセスできなくなる可能性があります。ご自身の IP アドレスを除外リストに追加するか、リスクを承知のうえで確認してください。',
    'ip_ignored' => 'その IP アドレスは除外リストに登録されています。本当にブロックする場合は、先に除外リストから削除してください。',
    'ip_own_address' => 'これはこのサーバー自身のアドレスです。ブロックするとパネルがデータベースから切り離され、アクセスできなくなるおそれがあるため、許可されていません。',
    'ip_your_address' => 'これは現在あなたが接続しているアドレスです。禁止するとこのサーバーに、recidive ジェイルではこのパネルにも接続できなくなるため、ここからは禁止できません。',
    'operation_failed' => 'fail2ban の操作に失敗しました。',
    'bantime_too_short' => 'ブロック時間は 60 秒以上、または無期限の場合は -1 を指定してください。',
    // FS-C45: the application's own ban list, ban and unban.
    'app_jail_not_enabled' => 'このアプリの fail2ban jail は動作していません。先に fail2ban の保護をオンにしてください。',
    // One ignore-list entry at a time (2026-10-16).
    'ignore_ip_exists' => 'このアドレスはすでに無視リストにあります。',
    'ignore_ip_missing' => 'このアドレスは無視リストにありません。ページを再読み込みして現在のリストを確認してください。',
    'ignore_list_full' => '無視リストがいっぱいです（100 件）。1 件削除するか、複数をまとめた範囲を使ってください。',
];
