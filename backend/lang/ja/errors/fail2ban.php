<?php

return [
    'not_installed' => 'このサーバーに fail2ban はインストールされていません。',
    'already_installed' => 'fail2ban は既にインストールされています。',
    'not_running' => 'fail2ban はインストールされていますが実行されていません。',
    'foreign_jail_local' => ':path はパネルが書き込んだファイルではないため、上書きしません。fail2ban は手動または別のパネルによってそこで設定済みです。パネルに管理させたい場合は、そのファイルを移動または削除してください。',
    'jail_not_active' => ':jail の jail は有効ではありません。',
    'not_banned' => 'その IP アドレスは現在ブロックされていません。',
    'lockout_risk' => 'SSH の jail を有効にすると、このサーバーにアクセスできなくなる可能性があります。ご自身の IP アドレスを除外リストに追加するか、リスクを承知のうえで確認してください。',
    'ip_ignored' => 'その IP アドレスは除外リストにあるため、ブロックは維持されません。',
    'operation_failed' => 'fail2ban の操作に失敗しました。',
    'bantime_too_short' => 'ブロック時間は 60 秒以上、または無期限の場合は -1 を指定してください。',
];
