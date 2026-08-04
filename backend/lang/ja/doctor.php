<?php

return [
    'checks' => [
        'privilege' => '特権コマンド',
        'services' => 'サービス',
        'writable_paths' => '書き込み可能なパス',
        'database' => 'データベース',
        'health_endpoint' => 'ヘルスエンドポイント',
        'binaries' => '必要なツール',
        'web_server' => 'ウェブサーバー',
        'queue' => 'キューワーカー',
    ],
    'fixes' => [
        'privilege' => 'パネルが root としてコマンドを実行できません。/etc/sudoers.d/ にパネルの許可があり、visudo -c を通ることを確認してください。',
        'privilege_disabled' => '権限昇格が無効ですがパネルは root ではありません。.env から SERVER_OPS_SUDO=false を削除してください。',
        'services_missing' => '想定するユニットが存在しません。.env の PANEL_FRONTEND_SERVICE と PANEL_QUEUE_SERVICE をこのサーバーの実際の名前に設定してください。',
        'services_down' => 'systemctl start で起動し、journalctl -u <ユニット> で停止理由を確認してください。',
        'writable_paths' => 'パネルアカウントに所有権を与えてください: 上記のパスに chown -R <パネルユーザー> を実行します。',
        'database_unreachable' => '.env の DB_ 設定と、データベースサービスが動作しているか確認してください。',
        'database_pending' => 'php artisan migrate --force を実行してください。スキーマ変更を適用せずにコードが更新されています。',
        'health_unreachable' => '.env の APP_URL がパネルの提供アドレスと一致し、ウェブサーバーと php-fpm が動作しているか確認してください。',
        'health_version_mismatch' => '実行中のコードと配信バージョンが異なります。php artisan optimize:clear でキャッシュを消去し php-fpm を再読み込みしてください。',
        'binaries_required' => '不足しているパッケージをインストールしてください。これがないと主要な機能がまったく動作しません。',
        'binaries_optional' => '不足している各ツールは横に示した機能を無効にします。セットアップページからインストールするか、不要であれば無視してください。',
        'web_server_missing' => '対応するウェブサーバーが見つかりません。nginx か Apache をインストールしてください。',
        'web_server_undrivable' => 'このウェブサーバー用の設定をパネルが書き出せないため、サイトを作成できません。nginx か Apache に切り替えてください。',
        'web_server_config' => 'ウェブサーバーの設定が不正です。設定テストを実行してください — 修正するまで次のリロードは失敗します。',
        'queue_stalled' => 'ジョブがキューにありますが処理されていません。キューサービスを再起動してください。プロビジョニング・デプロイ・インストールは完了しません。',
        'queue_failed_jobs' => '一部のバックグラウンドジョブが失敗しました。failed_jobs テーブルを確認してください — 黙って破棄された処理が「何も起きない」原因であることが多いです。',
        'queue_unreadable' => 'キューのテーブルを読み取れませんでした。php artisan migrate --force を実行してください。',
    ],
];
