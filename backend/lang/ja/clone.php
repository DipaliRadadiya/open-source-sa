<?php

return [
    'status' => [
        'pending' => '待機中',
        'running' => '複製中',
        'completed' => '完了',
        'failed' => '失敗',
    ],

    'current_step' => [
        'provisioning' => 'サイトを作成しています',
        'copying_files' => 'ファイルをコピーしています',
        'cloning_database' => 'データベースを複製しています',
        'starting_process' => 'アプリケーションを起動しています',
    ],

    'cloning_errors' => [
        'crashed' => '複製が予期せず停止しました。',
        'failed' => '複製に失敗しました。サポートに参照番号をお伝えください。',
        'abandoned' => 'この複製は開始されなかったため解除されました。もう一度開始してください。',
        'copy_failed' => 'サーバーでアプリケーションのコピーに失敗しました。サポートに参照番号をお伝えください。',
        'setup_failed' => 'サーバー上でコピーを準備できませんでした。サポートに参照番号をお伝えください。',
    ],

    'errors' => [
        'already_running' => 'このアプリケーションは既に複製中です。完了を待ってから、別の複製を開始してください。',
    ],
];
