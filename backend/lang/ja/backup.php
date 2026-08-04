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
        'not_configured' => 'このアプリケーションのバックアップはまだ設定されていません。',
        'already_running' => 'このアプリケーションのバックアップはすでに実行中です。',
        'dump_database' => 'データベースをダンプできなかったため、何もアップロードされていません。',
        'archive_files' => 'アーカイブを作成できませんでした。通常はサーバーのディスク容量不足です。',
        'upload_artifact' => 'アーカイブをアップロードできませんでした。保存先が書き込みを受け付けるか確認してください。',
        'verify_artifact' => 'アップロード内容が送信内容と一致しないため、このバックアップは信頼できません。古いバックアップは削除していません。',
        'unknown' => '不明な理由でバックアップに失敗しました。',
        'prune_old_backups' => '古いバックアップを削除できませんでした。新しいバックアップは無事です。保存先に設定より多くのコピーが残っている可能性があります。',
    ],
];
