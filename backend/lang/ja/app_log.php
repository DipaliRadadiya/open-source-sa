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

        'not_downloadable' => 'このログはファイルではありません。コンテナの出力は Docker が保持しています。画面で確認してください。',
        'unknown_source' => 'このアプリケーションにそのログはありません。',
        'clear_shared' => 'OpenLiteSpeed では、ファイアウォールの検出はこのサイトのアクセスログの一部です。代わりにアクセスログをクリアしてください。',
    ],
];
