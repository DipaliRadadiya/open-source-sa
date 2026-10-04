<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version はインストールされていません。',
    'version_in_use' => 'Node :version は :apps が使用しています。サイトの Node バージョンはまだ変更できないため、このバージョンを残すか、先にそれらのサイトを削除してください。',
    'version_unknown' => 'Node.js :version は存在しません。一覧からバージョンを選んでください。',
    'version_is_default' => 'これは既定のバージョンです。先に別のものを選んでください。',
    'version_runs_panel' => 'パネル自体が Node :version で動作しています。削除できません。',
    'npm_target_unknown' => 'npm のリリース一覧に接続できなかったため、この Node バージョンで動作する npm を判断できません。サーバーがインターネットに接続できる状態で再試行するか、`php artisan runtimes:refresh-npm` を実行してください。',
    'not_a_node_server' => 'このサーバーはコンテナをホストしており、ホスト自体ではアプリケーションを実行しないため、管理する Node.js のバージョンはありません。ランタイムはコンテナが自分で持ち込みます。',
];
