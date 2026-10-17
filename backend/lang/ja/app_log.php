<?php

return [
    'sources' => [
        'container' => 'コンテナ',
        'access' => 'アクセスログ',
        'error' => 'エラーログ',
        'application' => 'アプリケーションの出力',
        'application_error' => 'アプリケーションのエラー',
        'waf_detect' => 'ファイアウォール検出',
    ],

    'errors' => [
        'range_unsupported' => '行番号はログファイルにのみ使えます。システムジャーナルとコンテナー出力には固定の行番号がありません — 代わりに最新の行を読んでください。',
        'fields_unsupported' => 'IP、ステータス、メソッド、URL のフィルターはアクセスログにのみ使えます。このログにはテキスト検索を使ってください。',

        'not_downloadable' => 'このログはファイルではありません。コンテナの出力は Docker が保持しています。画面で確認してください。',
        'unknown_source' => 'このアプリケーションにそのログはありません。',
        'clear_shared' => 'OpenLiteSpeed では、ファイアウォールの検出はこのアプリケーションのアクセスログの一部です。代わりにアクセスログをクリアしてください。',
    ],
];
