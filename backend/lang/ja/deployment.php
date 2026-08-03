<?php

return [

    // Where one deploy got to. Shown as a badge on every row.
    'status' => [
        'queued' => '待機中',
        'running' => '実行中',
        'succeeded' => '成功',
        'failed' => '失敗',
    ],

    // What started it. "Push" rather than "Webhook" because the user
    // thinks in terms of what they did, not how it reached us.
    'trigger' => [
        'manual' => '手動',
        'webhook' => 'プッシュ',
        'redeploy' => '再実行',
    ],

];
