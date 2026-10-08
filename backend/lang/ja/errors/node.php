<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version はインストールされていません。',
    'version_in_use' => 'Node :version は :apps で使用中です。先にそれらのアプリケーションを別の Node バージョンに切り替える(各アプリケーションの Node.js 設定)か、削除してください。',
    'version_unknown' => 'Node.js :version は存在しません。一覧からバージョンを選んでください。',
    'version_is_default' => 'これは既定のバージョンです。先に別のものを選んでください。',
    'version_runs_panel' => 'パネル自体が Node :version で動作しています。削除できません。',
    'npm_target_unknown' => 'npm のリリース一覧に接続できなかったため、この Node バージョンで動作する npm を判断できません。サーバーがインターネットに接続できる状態で再試行するか、`php artisan runtimes:refresh-npm` を実行してください。',
    'not_a_node_server' => 'このサーバーはコンテナをホストしており、ホスト自体ではアプリケーションを実行しないため、管理する Node.js のバージョンはありません。ランタイムはコンテナが自分で持ち込みます。',
    'change_not_node' => 'Node バージョンを変更できるのは Node.js で動作するアプリケーションだけです。',
    'change_legacy_pm2' => 'このアプリケーションは以前のパネルの PM2 でまだ動作しています。先にパネルのプロセスマネージャーに切り替えてから Node バージョンを変更してください。',
    'change_in_progress' => 'このアプリケーションはすでに Node :version への切り替え中です。完了するまでお待ちください。',
    'change_use_endpoint' => 'Node バージョンは専用の操作(PUT /applications/{application}/node-version)で変更します。アプリケーションを再起動して動作を確認します。',
    'change_failed' => [
        'did_not_start' => 'アプリケーションが Node :target で起動しなかったため、Node :current に戻し、以前どおり動作しています。理由はアプリケーションのログを確認してください。',
        'rollback_failed' => 'アプリケーションが Node :target で起動せず、Node :current に戻すこともできませんでした。アプリケーションのログを確認して再起動してください。',
        'unit_write' => 'アプリケーションのサービスを Node :target 用に更新できませんでした。何も変更されておらず、Node :current のままです。',
        'install_pm2' => 'このアプリケーションが複数プロセスで動作するために必要な PM2 を Node :target にインストールできませんでした。何も変更されておらず、Node :current のままです。',
        'worker' => 'Node :target への切り替えが完了前に停止しました。アプリケーションは Node :current として記録されています。動作していることを確認してから、もう一度お試しください。',
    ],
    'remove_failed' => 'Node :version を削除できませんでした。サポートに下の参照番号をお伝えください。',
    'remove_failed_said' => 'Node :version を削除できませんでした。fnm のメッセージ:「:output」',
    'install_in_progress' => 'Node :version はすでにインストール中です。そのインストールが終わるまでお待ちください。',
];
