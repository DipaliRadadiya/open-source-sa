<?php

return [

    'install_steps' => [
        'queued' => '待機中',
        'checking_conflicts' => '競合するデータベースエンジンを確認しています',
        'preparing_repository' => 'パッケージリポジトリを準備しています',
        'waiting_for_package_manager' => '他のパッケージ操作の完了を待機しています',
        'updating_package_index' => 'パッケージインデックスを更新しています',
        'preparing' => 'パッケージを準備しています',
        'downloading' => 'パッケージをダウンロードしています',
        'unpacking' => 'パッケージを展開しています',
        'configuring' => 'パッケージを設定しています',
        'starting_service' => 'データベースサービスを起動しています',
        'verifying_connection' => 'データベース接続を確認しています',
        'creating_panel_account' => 'パネル用データベースアカウントを作成しています',
    ],

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'データベースのダンプに失敗しました。下記の参照番号をサポートにお伝えください。',
        'database_missing' => 'エクスポートを実行する前にデータベースが削除されました。',
        'worker' => 'エクスポートが予期せず停止しました。タイムアウトの可能性があります — 再試行してください。',
        'unknown' => 'エクスポートに失敗しました。下記の参照番号をサポートにお伝えください。',
    ],

];
