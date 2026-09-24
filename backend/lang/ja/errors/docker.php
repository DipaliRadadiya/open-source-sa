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
    'network_create_failed' => 'ネットワークを作成できませんでした。参照 :reference。',
    'network_remove_failed' => 'ネットワークを削除できませんでした。参照 :reference。',
    'volume_create_failed' => 'ボリュームを作成できませんでした。参照 :reference。',
    'volume_remove_failed' => 'ボリュームを削除できませんでした。参照 :reference。',
];
