<?php

return [
    'kinds' => [
        'queue' => 'キューワーカー',
        'horizon' => 'Horizon',
        'custom' => 'カスタム',
    ],

    'states' => [
        'running' => '実行中',
        'degraded' => '一部のみ実行中',
        'stopped' => '停止中',
    ],

    'presets' => [
        'queue' => [
            'title' => 'キューワーカー',
            'description' => 'キューに入ったジョブを処理します。通常はこちら。',
        ],
        'horizon' => [
            'title' => 'Horizon',
            'description' => '独自のキューワーカーをダッシュボード付きで管理します。キューワーカーと併用せず、置き換えて使います。',
        ],
        'custom' => [
            'title' => 'カスタムコマンド',
            'description' => '任意の長時間実行コマンドを起動し続けます。',
        ],
    ],

    'checks' => [
        'cache_driver_array' => [
            'title' => 'ワーカーを自動的に再起動できません',
            'detail' => 'このアプリケーションはプロセス間で保持されない "array" キャッシュドライバーを使用しています。Laravel はキャッシュに印を残してワーカーを再起動するため、コマンドは成功しても何も起こりません。デプロイ後もワーカーは古いコードのまま動き続けます。redis、database、file のいずれかを使用してください。',
        ],
    ],

    'errors' => [
        'queue_conflict' => 'このアプリケーションにはすでにもう一方の種類のキューワーカーがあります。Horizon は独自のワーカーを管理するため、両方を動かすとすべてのジョブが二重に処理されます。',
    ],
];
