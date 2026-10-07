<?php

return [
    'registry_status' => [
        'connected' => '接続済み',
        'never_tested' => '未テスト',
        'failed' => '前回のテストは失敗',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'レジストリがこのユーザー名とトークンを拒否しました。',
        'unreachable' => 'このサーバーからレジストリに到達できませんでした。アドレスと、HTTPS で到達できるかを確認してください。',
        'unknown' => 'レジストリは理由を示さずに接続を拒否しました。Docker 自身の応答はサーバー操作ログにあります。',
    ],

    // Image discovery (DS-02): search, versions and inspect on the create form.
    'image' => [
        'invalid_reference' => 'Docker が受け付けるイメージ名ではありません。nginx、usememos/memos、ghcr.io/owner/app のような名前を使い、必要なら :バージョン を続けてください。リポジトリ名は小文字です。',
        'not_found' => ':image という名前のイメージは見つかりませんでした。綴りを確認するか、非公開の場合は読み取れるレジストリ認証情報を選んでください。',
        'tag_not_found' => 'イメージ :image にバージョン :tag はありません。バージョン一覧から選んでください。',
        'credential_rejected' => '選択したレジストリ認証情報では :image を読み取れません。イメージが存在し、トークンに pull 権限があるか確認してください。',
        'registry_unreachable' => 'このサーバーからレジストリ :registry に接続できなかったため、イメージを確認できませんでした。ポートは自分で入力できます。',
        'rate_limited' => 'Docker Hub がこのサーバーからの問い合わせ回数を制限しています。数分後にやり直すか、ポートを自分で入力してください。',
        'blocked_host' => 'パネルはそのレジストリアドレスに接続しません。ループバックおよびリンクローカルアドレスは拒否されます。',
        'warning_required_env' => 'このイメージを起動するには次の設定が必要です: :keys。',
        'warning_empty_env' => 'これらの設定はイメージ内で空です。イメージのドキュメントで求められている場合のみ入力してください: :keys。',
        'warning_no_build' => 'このイメージには :architecture 向けのビルドがないため、このサーバーでは動きません。',
        'warning_large' => 'このイメージのダウンロードサイズは :size です。初回起動には時間がかかります。',
        'warning_no_port' => 'このイメージは待ち受けポートを宣言していません。イメージのドキュメントにあるポートを入力してください。',
        'warning_several_ports' => 'このイメージは複数のポート (:ports) で待ち受けます。サイト用にポート :port を選びました。ドキュメントの指定が異なる場合は変更してください。',
    ],
];
