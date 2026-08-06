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

    'already_disabled' => 'このアプリケーションはすでに無効になっています。',
    'not_disabled' => 'このアプリケーションは無効になっていません。',
    'availability_failed' => 'アプリケーションの可用性の変更がサーバーで失敗しました。',
    'basic_auth_failed' => 'パスワード保護の変更がサーバーで失敗しました。',
    'bot_blocker_failed' => 'AIボットブロッカーのポリシー変更がサーバーで失敗しました。',
    'web_root_failed' => 'ウェブルートの変更がサーバーで失敗しました。',
    'waf_failed' => 'ファイアウォール設定の変更がサーバーで失敗しました。',
    'staging_failed' => 'ステージング操作がサーバーで失敗しました。',
    'clone_failed' => 'クローン操作がサーバーで失敗しました。',
    'fail2ban_failed' => 'fail2ban操作がサーバーで失敗しました。',

    'permissions_fix_failed' => 'サーバー上でのファイル権限のリセットに失敗しました。',

    'unsafe_path' => 'そのパスは許可されていません。',
    'file_too_large' => 'このファイルは大きすぎるため、ここでは開けません。大きなファイルには SFTP を使用してください。',
    'file_not_text' => 'このファイルはテキストではないようで、ここでは開けません。',
    'file_operation_failed' => 'サーバー上でのファイル操作に失敗しました。',

    'file_not_archive' => 'ここでは .zip と .tar.gz アーカイブのみ展開できます。',
    'archive_unreadable' => 'そのアーカイブを読み込めませんでした。破損している可能性があります。',
    'archive_empty' => 'そのアーカイブには何も含まれていません。',
    'archive_too_many_entries' => 'そのアーカイブはファイル数が多すぎて、ここでは展開できません。',
    'archive_too_large' => 'そのアーカイブは展開すると大きくなりすぎます。',
    'archive_has_symlink' => 'そのアーカイブにはシンボリックリンクが含まれており、許可されていません。',
    'archive_unsafe_entry' => 'そのアーカイブには許可されていないファイルパスが含まれています。',

    'path_exists' => 'そのパスにはすでに何かが存在します。',
    'cannot_delete_root' => 'サイトのルートフォルダは削除できません。',
    'target_not_zip' => '新しいアーカイブの名前は .zip で終わる必要があります。',
    'unknown_backup' => 'それはこのファイルの既知のバックアップではありません。',

];
