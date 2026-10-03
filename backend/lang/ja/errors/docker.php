<?php

return [
    'not_a_docker_server' => 'このサーバーはコンテナをホストしていないため、Docker のネットワークやボリュームはありません。',
    'invalid_name' => '名前には英字・数字・ドット・ハイフン・アンダースコアを使用でき、先頭は英字または数字である必要があります。',
    'network_exists' => ':name という名前のネットワークはすでに存在します。',
    'volume_exists' => ':name という名前のボリュームはすでに存在します。',
    'network_built_in' => ':name は Docker 自身のネットワークです。再起動時に Docker が再作成するため、削除するとこのサーバー上のすべてのコンテナが動かなくなります。',
    'network_in_use' => 'ネットワーク :name にはまだコンテナが接続されています: :containers。先に停止または切断してください。',
    'network_used_by_sites' => '次のサイトはネットワーク :name に参加する設定です: :sites。先にそれらのネットワークを変更してください。今削除すると起動できなくなります。',
    'volume_in_use' => 'ボリューム :name はまだ :count 個のコンテナで使用されています。先に停止してください。使用中のボリュームを削除すると、書き込み中のデータが失われます。',
    'volume_in_use_by' => 'ボリューム :name は :containers がまだ使用しています。先に停止してください。使用中のボリュームを削除すると、書き込み中のデータが消えます。',
    'volume_used_by_sites' => '次のサイトがボリューム :name をマウントしています: :sites。先にマウントを解除してください。今削除すると、保存されているデータが失われます。',
    'network_create_failed' => 'ネットワークを作成できませんでした。参照 :reference。',
    'network_remove_failed' => 'ネットワークを削除できませんでした。参照 :reference。',
    'volume_create_failed' => 'ボリュームを作成できませんでした。参照 :reference。',
    'volume_remove_failed' => 'ボリュームを削除できませんでした。参照 :reference。',
    'registry_deleted' => 'レジストリ認証情報を削除しました。これを使っていたサイトは今後、匿名でイメージを取得します。',
    'registry_credential_unwritable' => 'レジストリ認証情報をディスクに書き込めなかったため、Docker には問い合わせていません。参照 :reference。',
    'database_start_failed' => 'データベースを起動できませんでした（:step）。行・コンテナ・確保されたポートのいずれも残っていません。',
    'database_version_unknown' => 'そのエンジンに :version というバージョンはありません。:engine 用にパネルが提供するものから選んでください。',
    'database_deleted' => 'データベースを削除しました。',
];
