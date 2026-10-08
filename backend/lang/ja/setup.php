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
            'description' => 'アプリケーションが必要とする場合は別のバージョンを追加できます。',
        ],
        'node' => [
            'title' => 'Node.js',
            'description' => 'fnm で管理するため、アプリケーションごとにバージョンを固定できます。',
        ],
        'redis' => [
            'title' => 'Redis',
            'description' => 'パネルのキャッシュに使用します。ない場合はデータベースを使用します。動作しますが低速です。',
        ],
        'fail2ban' => [
            'title' => 'fail2ban',
            'description' => 'SSH やアプリケーションへの繰り返しのログイン失敗をブロックします。',
        ],
        'build_tools' => [
            'title' => 'ビルドツール',
            'description' => 'ビルド済みで配布されないアプリ部品をサーバー上でコンパイルできるようにします。一部の Node アプリはインストールに必要です。',
        ],
        'wp_cli' => [
            'title' => 'WP-CLI',
            'description' => 'WordPress のコマンドラインツールです。パネルはすべての WordPress 操作に使用し、ない場合は最初の WordPress アプリケーション作成時に取得します。',
        ],
    ],

];
