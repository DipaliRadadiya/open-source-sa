<?php

return [
    'steps' => [
        'dump_database' => 'データベースをダンプ中',
        'archive_files' => 'アーカイブを作成中',
        'upload_artifact' => 'ストレージへアップロード中',
        'verify_artifact' => 'アップロードを検証中',
        'prune_old_backups' => '古いバックアップを削除中',
        'rollback' => '後処理中',
    ],
    'status' => [
        'pending' => '待機中',
        'running' => 'バックアップ中',
        'verifying' => '検証中',
        'verified' => '完了',
        'failed' => '失敗',
    ],
    'type' => [
        'filesystem' => 'ファイル',
        'database' => 'データベース',
        'full' => 'ファイルとデータベース',
    ],
    'frequency' => [
        'manual' => '手動のみ',
        'daily' => '毎日',
        'weekly' => '毎週',
        'monthly' => '毎月',
    ],
    'errors' => [
        'restore_unverified' => 'このバックアップは検証されていないため復元できません。',
        'restore_no_application' => 'このバックアップのアプリケーションは既に存在しません。',
        'restore_confirm' => '復元を確認するには、アプリケーションのドメインを正確に入力してください。',
        'restore_already_running' => 'このアプリケーションの復元はすでに実行中です。',
        'restore_no_database' => 'このバックアップにデータベースは含まれていません。',
        'restore_no_files' => 'このバックアップにファイルは含まれていません。',
        'download_no_artifact' => 'このバックアップはアップロードが完了しなかったため、ダウンロードできるアーカイブがありません。',
        'download_no_destination' => 'このバックアップのアップロード先の保存先はすでに存在しません。',
        'download_missing' => 'アーカイブは保存先にすでに存在しません。',
        'not_configured' => 'このアプリケーションのバックアップはまだ設定されていません。',
        'delete_running' => 'このバックアップは実行中のため、まだ削除できません。完了または失敗するまでお待ちください。',
        'delete_artifact' => 'アーカイブをストレージ先から削除できなかったため、何も削除していません。保存先に接続できるか確認して再試行してください。',
        'delete_target_running' => 'このアプリケーションのバックアップが実行中です。バックアップを無効にする前に完了をお待ちください。',
        'delete_target_has_backups' => 'このアプリケーションにはまだ :count 件のバックアップがあります。それらも削除してよいか確認するか、先に削除してください。',
        'already_running' => 'このアプリケーションのバックアップはすでに実行中です。',
        'dump_database' => 'データベースをダンプできなかったため、何もアップロードされていません。',
        'archive_files' => 'アーカイブを作成できませんでした。通常はサーバーのディスク容量不足です。',
        'upload_artifact' => 'アーカイブをアップロードできませんでした。保存先が書き込みを受け付けるか確認してください。',
        'verify_artifact' => 'アップロード内容が送信内容と一致しないため、このバックアップは信頼できません。古いバックアップは削除していません。',
        'unknown' => '不明な理由でバックアップに失敗しました。',
        'prune_old_backups' => '古いバックアップを削除できませんでした。新しいバックアップは無事です。保存先に設定より多くのコピーが残っている可能性があります。',
    ],

    'restore_status' => [
        'pending' => '待機中',
        'running' => '復元中',
        'succeeded' => '復元しました',
        'failed' => '復元に失敗しました',
    ],

    'restore_steps' => [
        'download_artifact' => 'バックアップをダウンロードしています',
        'verify_download' => 'バックアップが壊れていないか確認しています',
        'safety_backup' => '先に現在の状態をバックアップしています',
        'extract_archive' => 'バックアップを展開しています',
        'restore_database' => 'データベースを復元しています',
        'swap_files' => 'ファイルを配置しています',
        'restart_process' => 'アプリケーションを起動しています',
    ],

    'restore_errors' => [
        'download_artifact' => 'バックアップをダウンロードできませんでした。サーバー上は何も変更されていません。',
        'verify_download' => 'ダウンロードしたバックアップが不完全または破損しているため使用しませんでした。サーバー上は何も変更されていません。',
        'safety_backup' => '現在の状態をバックアップできなかったため、復元を中止しました。何も上書きされていません。',
        'extract_archive' => 'バックアップを展開できませんでした。サーバー上は何も変更されていません。',
        'restore_database' => 'データベースを復元できませんでした。事前に取得したバックアップに以前の状態が残っています。',
        'swap_files' => 'ファイルを配置できませんでした。以前のサイトディレクトリを元に戻しました。',
        'restart_process' => 'ファイルとデータベースは復元されましたが、アプリケーションが起動しませんでした。ログを確認してください。',
        'missing_backup' => '復元を開始する前にバックアップが削除されました。',
        'crashed' => '復元が予期せず停止しました。再試行する前にバックアップを確認してください。',
        'unknown' => '不明な理由で復元に失敗しました。',
    ],

    'cloning' => [
        'provisioning' => 'サイトを作成しています',
        'copying_files' => 'ファイルをコピーしています',
        'cloning_database' => 'データベースを複製しています',
        'starting_process' => 'アプリケーションを起動しています',
    ],

    'cloning_errors' => [
        'crashed' => '複製が予期せず停止しました。',
    ],

    'schedule_time' => '予定時刻',
];
