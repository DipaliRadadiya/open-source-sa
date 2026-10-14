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
        'verifying_cluster' => 'データベースクラスタを確認しています',
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

    'import_failed' => [
        'unknown' => 'インポートに失敗しました。下の参照番号をサポートにお伝えください。',
        'database_missing' => 'インポートの実行前にデータベースが削除されました。',
        'no_account' => 'このデータベースには、インポートを実行できるユーザー（パネルがパスワードを把握していて、このサーバーから接続するユーザー）がいません。',
        'wrong_format' => 'このファイルはこのデータベースでインポートできるダンプではありません。MySQL と MariaDB は .sql または .sql.gz、PostgreSQL は .sql、.sql.gz、または pg_dump のカスタム形式アーカイブ、MongoDB は mongodump --archive のファイルを受け付けます。',
        'client_command' => 'ファイルの :line 行目に psql コマンド（\\! などのバックスラッシュコマンド）が含まれています。このようなコマンドはサーバー上でプログラムを実行できるため、ファイルを拒否しました。pg_dump でプレーン SQL として再度エクスポートしてください。',
        'truncated' => 'ファイルがデータの途中で終わっており、不完全です。もう一度エクスポートしてください。',
        'unreadable' => 'ファイルを PostgreSQL アーカイブとして読み取れませんでした。',
        'backup_failed' => 'インポート前のバックアップに失敗したため、何もインポートしていません。',
        'load_failed' => 'ファイルをデータベースに読み込めませんでした。下の参照番号をサポートにお伝えください。',
        'worker' => 'インポートが予期せず停止しました。タイムアウトした可能性があります。',
        'abandoned' => 'アップロードが 1 日以内に完了しなかったため、削除しました。',
        'restored' => 'データベースは自動バックアップから元に戻したため、失われたものはありません。',
        'untouched' => 'データベースは変更されていません。',
        'not_restored' => '自動バックアップを自動で戻すことができませんでした。エクスポート一覧にあるので、ダウンロードしてインポートするとデータベースを元に戻せます。',
    ],

];
