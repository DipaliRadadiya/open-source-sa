<?php

return [
    'registry_status' => [
        'connected' => '接続済み',
        'never_tested' => '未テスト',
        'failed' => '前回のテストは失敗',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'レジストリがこのユーザー名とトークンを拒否しました。',
        'unreachable' => 'このサーバーからレジストリに到達できませんでした。アドレスと、HTTPS で到達できるかを確認してください。',
        'unknown' => 'レジストリは理由を示さずに接続を拒否しました。Docker 自身の応答はサーバー操作ログにあります。',
    ],
];
