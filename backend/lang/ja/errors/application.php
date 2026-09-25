<?php

return [
    'database_engine_not_used' => 'このアプリケーションはデータベースを使用しません。',
    'database_engine_unsupported' => 'このアプリケーションはそのデータベースエンジンを使用できません。:application は別のものに対応しています。',
    'database_engine_unavailable' => 'そのデータベースエンジンはこのサーバーで実行されていません。先にインストールまたは起動してください。',
    'database_engine_too_old' => 'このサーバーの :engine は :application には古すぎます。:minimum 以降が必要です。アップグレードするか、別のデータベースエンジンを選んでください。',

    // Deleting a site can take its databases with it (`remove_databases`).
    // The first refusal is the caller lacking `database` manage; the second
    // is the honest half-success — the site went, a database did not.
    'database_removal_not_permitted' => 'このサイトは削除できますが、データベースは削除できません。管理者にデータベースの権限を依頼するか、データベースを残したままサイトを削除してください。',
    'databases_not_removed' => 'サイトは削除されましたが、次のデータベースはサーバーに残っています: :databases。データベース画面から削除するか、参照 ID をサポートにお伝えください。',

    'primary_domain_not_removable' => 'プライマリドメインは削除できません。先に別のドメインをプライマリに設定してください。',
    'primary_domain_not_editable' => 'プライマリドメインは編集できません。先に別のドメインをプライマリにしてください。',
    'domain_taken' => 'このドメインはこのサーバーで既に使用されています。',
    'domain_taken_by' => 'このドメインはアプリケーション「:application」で既に使用されています。',
    'unsupported_web_server' => ':web_server 用のサイト設定は作成できません。',
    'no_web_server' => 'ウェブサーバーが検出されません',
    'provision_failed' => 'サイトのセットアップが「:step」の段階で失敗しました。',
    'not_a_git_application' => 'このアプリケーションはgitデプロイではないため、取得するものがありません。',
    'no_database_engine' => '利用可能なデータベースエンジンがありません。このアプリケーションを作成する前に MySQL または MariaDB を設定してください。',
    'no_process' => '「:name」は独自のプロセスを実行していません。',
    'process_failed' => 'アプリケーションを:actionできませんでした。参照番号をサポートにお伝えください。',
    'system_user_missing' => ':name のシステムユーザーが見つからないため、パネルはこのアプリケーションのファイルを操作できません。アプリケーションの削除は可能です。',
    'no_port_available' => ':from から :to の間に空きポートがありません。解放するか範囲を広げてください。',

    'webhook_not_a_git_application' => 'プッシュ時デプロイは、git リポジトリからデプロイされたアプリケーションでのみ利用できます。',

    'already_disabled' => 'このアプリケーションはすでに無効になっています。',
    'not_disabled' => 'このアプリケーションは無効になっていません。',
    'availability_failed' => 'アプリケーションの可用性の変更がサーバーで失敗しました。',
    'basic_auth_failed' => 'パスワード保護の変更がサーバーで失敗しました。',
    'environment_failed' => 'サーバー上でアプリケーションの環境ファイルを確認できなかったため、あるともないとも報告しません。',
    'bot_blocker_failed' => 'AIボットブロッカーのポリシー変更がサーバーで失敗しました。',
    'bot_agent_invalid' => 'GPTBot や SemrushBot のようなボット名を1つ入力してください（英数字、ドット、ハイフンのみ）。',
    'bot_agent_too_broad' => '指定が大まかすぎます。Google や Bing などの検索エンジンもブロックされます。ボットの正式名称を使用してください。',
    'bot_agent_search_engine' => 'これはAIクローラーではなく検索エンジンです。ブロックするとサイトが検索結果から消えます。',
    'web_root_failed' => 'ウェブルートの変更がサーバーで失敗しました。',
    'web_root_not_found' => 'サーバー上にウェブルートディレクトリが見つかりませんでした。アプリケーション設定のウェブルートを確認し、作成されていない場合は再プロビジョニングしてください。',
    'waf_unsupported' => '8G ファイアウォールは :server ではまだ利用できません。',
    'waf_failed' => 'ファイアウォール設定の変更がサーバーで失敗しました。',
    'staging_failed' => 'ステージング操作がサーバーで失敗しました。',
    'staging_rollback_failed' => 'ステージングの反映に失敗し、本番環境を復元できませんでした。サイトは無効のままです。参照番号をサポートにお伝えください。',
    'clone_failed' => 'クローン操作がサーバーで失敗しました。',
    'fail2ban_failed' => 'fail2ban操作がサーバーで失敗しました。',

    'permissions_fix_failed' => 'サーバー上でのファイル権限のリセットに失敗しました。',

    'unsafe_path' => 'そのパスは許可されていません。',
    'file_too_large' => 'このファイルはエディターで開くには大きすぎます。代わりにダウンロードしてください（ダウンロードにサイズ制限はありません）。',
    'file_not_text' => 'このファイルはテキストではないようで、ここでは開けません。',
    'file_not_previewable' => 'このファイルは画像ではないため、表示できるものがありません。ダウンロードしてお使いの端末で開いてください。',
    'file_svg_not_previewable' => 'SVG ファイルはコードを含むことがあるため、ここでは表示しません。ダウンロードしてご覧ください。',
    'file_too_large_to_preview' => 'この画像はここに表示するには大きすぎます。代わりにダウンロードしてください（ダウンロードにサイズ制限はありません）。',

    'archive_failed' => [
        'timed_out' => 'アーカイブの作成がサーバーの許容時間を超えたため停止しました。選択を減らしてお試しください。',
        'command_failed' => 'サーバーはアーカイブを完了できませんでした。書きかけのファイルは残っていません。',
        'application_missing' => 'アーカイブを作成する前にサイトが削除されました。',
        'worker' => 'サーバー上で処理が予期せず停止し、完了しませんでした。',
        'unknown' => 'アーカイブは完了しませんでした。',
    ],
    'file_operation_failed' => 'サーバー上でのファイル操作に失敗しました。',

    'file_not_archive' => 'ここでは .zip と .tar.gz アーカイブのみ展開できます。',
    'archive_unreadable' => 'そのアーカイブを読み込めませんでした。破損している可能性があります。',
    'archive_empty' => 'そのアーカイブには何も含まれていません。',
    'archive_too_many_entries' => 'そのアーカイブはファイル数が多すぎて、ここでは展開できません。',
    'archive_too_large' => 'そのアーカイブは展開すると大きくなりすぎます。',
    'archive_has_symlink' => 'そのアーカイブにはシンボリックリンクが含まれており、許可されていません。',
    'archive_unsafe_entry' => 'そのアーカイブには許可されていないファイルパスが含まれています。',

    'upload_exists' => '「:name」はすでに存在します。置き換える場合は先に削除してください。アップロードでファイルが上書きされることはありません。',

    'path_exists' => 'そのパスにはすでに何かが存在します。',
    'cannot_delete_root' => 'サイトのルートフォルダは削除できません。',
    'target_not_archive' => '新しいアーカイブ名は .zip、.tar.gz、.tgz のいずれかで終わる必要があります。',
    'unknown_backup' => 'それはこのファイルの既知のバックアップではありません。',

    'upload_directory_missing' => 'このアップロード先のフォルダーは存在しなくなりました。',
    'upload_insufficient_space' => 'このアップロードに必要な空きディスク容量がサーバーにありません。',

    'bulk_count_mismatch' => '確認した件数が、選択されている項目の数と一致しません。',
    'sources_not_in_one_directory' => '圧縮する項目はすべて同じフォルダー内にある必要があります。',
    'release_failed' => 'サーバー上にサイトのディレクトリを作成できませんでした。',
    'supervisor_missing' => 'ワーカーには supervisord が必要ですが、このサーバーにはインストールされていません。`apt-get install supervisor` でインストールしてから、ワーカーを作成し直してください。',
    'supervisor_already_installed' => 'この サーバーには supervisor が既にインストールされています。',
    'worker_control_failed' => 'サーバー上でワーカーを制御できませんでした。',

    // Which system account a new site runs as. Generating one creates a
    // real Linux account, which is why it needs its own permission.
    'generate_system_user_forbidden' => 'システムユーザーを作成する権限がないため、このサイト用に新しいユーザーを生成できません。既存のシステムユーザーを選択してください。',
    'system_user_conflict' => '新しいシステムユーザーか既存のシステムユーザーのどちらかを選択してください。両方は指定できません。',
    'system_user_name_unavailable' => 'このサイト用のシステムユーザー名を確保できませんでした。どの名前が使用中かをサーバーに問い合わせられませんでした。もう一度お試しになるか、既存のシステムユーザーを選択してください。',

    // The Lock button for a site folder the panel did not create; see
    // SiteRootLock::adopt(). Keyed by its result.
    'root_lock' => [
        'unsafe' => 'サイトフォルダー :path は通常のフォルダーではないか、確認中に変更されたため、変更しませんでした。サーバー上で確認してから再度お試しください。',
        'missing' => 'サイトフォルダー :path がサーバー上に存在しません。',
        'failed' => 'サーバーがサイトフォルダーをロックできませんでした。何も変更されていません。詳細はサーバーログを確認してください。',
        'unsupported' => 'このサーバーのディスクはフォルダーロックに対応していないため、サイトフォルダーはそのままにしました。',
        'foreign_owner' => 'サイトフォルダー :path はこのサイトのユーザーではなく別のアカウントが所有しているため、変更しませんでした。ロックする前に所有者を確認してください。',
        'writable' => 'サイトフォルダー :path には他のアカウントが書き込めるため、ロックが機能しません。グループと全員の書き込み権限を外して(例: `chmod 755`)から再度お試しください。',
        'locks_out_user' => 'サイトフォルダー :path をロックすると、このサイトのユーザーがフォルダーを開けなくなります。現在の権限では所有者としてしかアクセスできません。フォルダーのグループに読み取りと開く権限を与え(例: `chmod 750`)、ユーザーがそのグループに属していることを確認してから再度お試しください。',
    ],

    // A git site whose account was disconnected: no credential, no URL.
    'git_account_missing' => 'この Git アカウントは接続されていないため、デプロイ元がありません。デプロイ画面で再接続してから、もう一度デプロイしてください。',
];
