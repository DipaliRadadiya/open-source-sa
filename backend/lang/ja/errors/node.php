<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version はインストールされていません。',
    'version_in_use' => 'Node :version は :apps が使用しています。先にそれらのサイトを変更してください。',
    'version_is_default' => 'これは既定のバージョンです。先に別のものを選んでください。',
    'npm_target_unknown' => 'npm のリリース一覧に接続できなかったため、この Node バージョンで動作する npm を判断できません。サーバーがインターネットに接続できる状態で再試行するか、`php artisan runtimes:refresh-npm` を実行してください。',
];
