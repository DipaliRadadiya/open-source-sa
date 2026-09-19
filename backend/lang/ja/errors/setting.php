<?php

return [
    'operation_failed' => 'サーバーでの設定変更に失敗しました。',
    'group_unavailable' => 'その設定グループはこのサーバーでは利用できません。',
    'security_updates_unavailable' => 'このサーバーには unattended-upgrades がインストールされていないため、パネルが実行できるものがありません。unattended-upgrades パッケージをインストールしてから再試行してください。',
    'security_updates_in_progress' => 'セキュリティ更新はすでに実行中です。',
    'no_ssh_key' => 'パスワード認証を無効にする前にSSHキーを追加してください。ロックアウトの恐れがあります。',
    'redis_credential_unusable' => 'パネルは保存しているパスワードでRedisに接続できないため、パスワードを変更できません。Redisは稼働していますが、パネルの認証情報を拒否しています。パネルの .env の REDIS_PASSWORD をRedisが実際に要求するパスワードに修正してから、再試行してください。',
    'env_not_writable' => 'パネルが自身の .env ファイルに書き込めないため、新しい Redis パスワードを保存できませんでした。先にファイルの権限を修正してください。そのままではパネルが Redis に接続できなくなります。',
    'swap_in_use' => 'スワップが使用中のため無効にできませんでした。スワップアウトされたデータを戻すための空きメモリがサーバーに不足しています。メモリを解放してから再試行してください。',
    'swap_below_minimum' => 'このサーバーではスワップを :minimum MB 未満にできません。パネルは更新時に自身のフロントエンドをビルドし、それにはメモリとスワップ合わせて :required MB が必要です。これを下回ると更新は途中で強制終了されます。RAM を増やすか、このサーバーのスワップをご自身で管理している場合は SERVER_SWAP_ENFORCE_MINIMUM=false を設定してください。',
];
