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
        'unreadable_key' => 'この行はパネルが読み取れる公開鍵ではないため、そのままにしました。アクセスを許可している可能性があるので手動で確認してください。',
        'discovery_failed' => 'サーバーから読み取れませんでした。何も変更されていません。',
        'adopt_failed' => 'サーバー上で見つかりましたが、パネルが記録を作成できませんでした。',
        'requires_system_user' => 'システムユーザーがこの実行に含まれておらず、先に必要なためスキップしました。',
    ],

];
