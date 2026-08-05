<?php

return [
    'primary_domain_not_removable' => 'プライマリドメインは削除できません。先に別のドメインをプライマリに設定してください。',
    'unsupported_web_server' => ':web_server 用のサイト設定は作成できません。',
    'no_web_server' => 'ウェブサーバーが検出されません',
    'provision_failed' => 'サイトのセットアップが「:step」の段階で失敗しました。',
    'not_a_git_application' => 'このアプリケーションはgitデプロイではないため、取得するものがありません。',
    'no_database_engine' => '利用可能なデータベースエンジンがありません。このアプリケーションを作成する前に MySQL または MariaDB を設定してください。',
    'no_process' => '「:name」は独自のプロセスを実行していません。',
    'process_failed' => 'アプリケーションを:actionできませんでした。参照番号をサポートにお伝えください。',
    'no_port_available' => ':from から :to の間に空きポートがありません。解放するか範囲を広げてください。',

    'webhook_not_a_git_application' => 'プッシュ時デプロイは、git リポジトリからデプロイされたアプリケーションでのみ利用できます。',

    'permissions_fix_failed' => 'サーバー上でのファイル権限のリセットに失敗しました。',

    'unsafe_path' => 'そのパスは許可されていません。',
    'file_too_large' => 'このファイルは大きすぎるため、ここでは開けません。大きなファイルには SFTP を使用してください。',
    'file_not_text' => 'このファイルはテキストではないようで、ここでは開けません。',
    'file_operation_failed' => 'サーバー上でのファイル操作に失敗しました。',

];
