<?php

return [

    'installing' => ':component をインストール中',

    'detail' => [
        'cache_in_use' => 'パネルのキャッシュに使用中',
    ],

    'components' => [
        'database' => [
            'title' => 'データベース',
            'description' => 'WordPress やデータを保存するアプリケーションをインストールする前に必要です。',
        ],
        'php' => [
            'title' => 'PHP',
            'description' => 'サイトが必要とする場合は別のバージョンを追加できます。',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'fnm で管理するため、サイトごとにバージョンを固定できます。',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'パネルのキャッシュに使用します。ない場合はデータベースを使用します。動作しますが低速です。',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'SSH やサイトへの繰り返しのログイン失敗をブロックします。',
        ],
        'build_tools' => [
            'title' => 'ビルドツール',
            'description' => 'ビルド済みで配布されないアプリ部品をサーバー上でコンパイルできるようにします。一部の Node アプリはインストールに必要です。',
        ],
    ],

];
