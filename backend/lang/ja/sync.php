<?php

/*
 * Reading a migrated server into the panel.
 *
 * `reasons` are why one discovered thing was skipped or failed. They are
 * shown per row in the run's list, because a sync that reports only what it
 * imported is indistinguishable from one that quietly missed half the box.
 */

return [

    'errors' => [
        'already_running' => '同期はすでに実行中です。終了してから次を開始してください。',
    ],

    'reasons' => [
        'firewall_direction_unsupported' => 'これは送信方向のルールです。パネルは受信ルールのみを管理するため、ここに記録すると逆方向に適用されてしまいます。',
        'firewall_action_unsupported' => 'このルールは許可・拒否ではなく制限または拒絶を行います。パネルに対応する設定がなく、単純な allow / deny として記録するとサーバーの動作を誤って表すことになります。',
        'firewall_app_profile' => 'このルールはポートではなくアプリケーションプロファイルを使っています。背後のポートはパッケージ更新で変わり得るため、現時点の番号を取り込むのはルールを装ったスナップショットになります。',
        'panel_infrastructure' => 'これはパネル自身であり、パネルがホストするサイトではありません。意図的にそのままにしています。',
        'outside_panel_layout' => 'このサイトはパネルが管理するディレクトリ構成ではないため、ファイルを移動せずに取り込むことはできません。配信は続いており、何も変更していません。',
        'vhost_unreadable' => 'このサイトのウェブサーバー設定を読み取れなかったため、そのままにしました。',
        'vhost_unparsed' => 'このサイトは配信されていますが、設定がパネルの読み取れる形式ではありません。手動で登録するか、ファイルを確認してください。',
        'owner_not_tracked' => 'このサイトを所有する Linux アカウントはパネルの管理対象ではありません。先にシステムユーザーを同期してから再実行してください。',
        'unreadable_key' => 'この行はパネルが読み取れる公開鍵ではないため、そのままにしました。アクセスを許可している可能性があるので手動で確認してください。',
        'discovery_failed' => 'サーバーから読み取れませんでした。何も変更されていません。',
        'adopt_failed' => 'サーバー上で見つかりましたが、パネルが記録を作成できませんでした。',
        'requires_system_user' => 'システムユーザーがこの実行に含まれておらず、先に必要なためスキップしました。',
    ],

];
