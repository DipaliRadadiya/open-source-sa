<?php

return [

    'waf' => [
        'modes' => [
            'detect' => '監視のみ(ブロックしない)',
            'enforce' => '実際にブロック',
        ],
        'categories' => [
            'query_string' => '不正な検索語句',
            'request_uri' => '不正なWebアドレス',
            'user_agent' => '不正な訪問者',
            'referrer' => '不正なリンク',
            'cookie' => '不正なクッキー',
            'method' => '不正なリクエスト形式',
        ],
    ],

];
