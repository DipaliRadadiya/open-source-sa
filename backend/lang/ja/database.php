<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'データベースのダンプに失敗しました。下記の参照番号をサポートにお伝えください。',
        'database_missing' => 'エクスポートを実行する前にデータベースが削除されました。',
        'worker' => 'エクスポートが予期せず停止しました。タイムアウトの可能性があります — 再試行してください。',
        'unknown' => 'エクスポートに失敗しました。下記の参照番号をサポートにお伝えください。',
    ],

];
