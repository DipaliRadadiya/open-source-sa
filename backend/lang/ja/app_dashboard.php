<?php

return [
    'issues' => [
        'certificate' => [
            'expired' => 'SSL 証明書の有効期限が切れています。',
            'expiring' => 'SSL 証明書はあと :days 日で期限切れになります。',
        ],
        'worker' => [
            'stopped' => 'アプリケーションのプロセスが停止しています。',
        ],
        'dns' => [
            'drift' => 'DNS がずれています。ドメインの IP が保存された値と一致しません。',
            'unresolved' => '現在、ドメインの DNS を解決できません。',
        ],
        'php_eol' => 'PHP :version はサポートが終了しており、セキュリティ更新は提供されません。',
        'disk' => 'ディスク使用率が :percent% です。',
        'deploy_failed' => '直近のデプロイが :step ステップで失敗しました。',
    ],
];
