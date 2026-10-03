<?php

return [
    'operation_failed' => 'サーバーでデータベース操作に失敗しました。',
    'export_already_running' => 'このデータベースのエクスポートはすでに実行中です。完了してから次を開始してください。',
    'collation_mismatch' => '選択した照合順序は、選択した文字セットに属していません。',
    'application_already_attached' => ':application にはすでに :database データベースが紐付いています。先にそちらの紐付けを解除するか、このデータベースを別のアプリケーションに紐付けてください。',
    'engine_not_accepted' => ':application は :engine データベースを使用できません。使用できるのは :accepted です。',
    'engine_not_installable' => 'このデータベースエンジンはまだパネルからインストールできません。ご自身でインストールすればパネルが検出します。',
    // The vendor publishes nothing for this Ubuntu release. Refused
    // before the install rather than discovered two minutes into apt.
    'engine_os_unsupported' => ':engine はまだ :os 向けのパッケージを公開していないため、パネルからはインストールできません。このサーバーに問題はありません。パネルは :os に対応していますが、:engine がまだ対応ビルドを出していないためです。別のデータベースエンジンを使うか、公開後に再度お試しください。',
    'phpmyadmin_engine_not_supported' => 'phpMyAdmin は :engine データベースに対応していません。',
    'phpmyadmin_not_deployed' => 'このサーバーにphpMyAdminサイトがインストールされていません。',
    'phpmyadmin_no_users' => 'phpMyAdminにアクセスする前にデータベースユーザーを作成してください。',
    'remote_users_unsupported' => ':engine ではリモートアクセスを利用できません。アカウントがホストに紐づかないためです。localhost を使用してください。',
    // 409, not 422: the request is fine, the cluster is not ready. The
    // client re-sends with restart_cluster as explicit consent.
    'remote_access_restart_required' => 'リモート接続を許可するには :engine の再起動が必要です。待ち受けアドレスは起動時にしか変更できないためです。このデータベースを使用しているアプリケーションは一時的に接続を失います。続行するには restart_cluster を付けて再送信してください。',
    'phpmyadmin_not_selectable' => '選択されたサイトは有効な phpMyAdmin インストールではありません。',
    'phpmyadmin_user_not_found' => '指定されたデータベースユーザーはこのデータベースに属していません。',
    'phpmyadmin_not_isolated' => 'このphpMyAdminサイトはサーバー全体のPHPプールを共有しているため、サインインリンクが他のすべてのサイトから読み取れてしまいます。専用のPHPプールを割り当てるか、phpMyAdminを開いてデータベースの認証情報でサインインしてください。',
    'phpmyadmin_requires_https' => 'この phpMyAdmin サイトには HTTPS がないため、サインインリンクとデータベースセッションが暗号化されずに送信されます。先に SSL 証明書を発行してください。',
    'user_exists' => 'データベースユーザー「:username」は既に存在します。別の名前を選んでください。',
    'phpmyadmin_sso_unavailable' => 'phpMyAdminサイトでサインインリンクを準備できませんでした。',
    'remote_host_invalid' => '203.0.113.5 や 203.0.113.0/24 のような IPv4 アドレスまたは範囲を入力してください。',
    'remote_host_not_remote' => 'そのアドレスはリモートではありません。このサーバーには「ローカル」、すべてのアドレスには「どこからでも」を使ってください。',
];
