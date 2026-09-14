<?php

return [

    // Why a Magic Login was refused. Each of these is a different thing to do
    // about it, and every one of them happens *before* a token exists — a
    // refused attempt leaves nothing behind on the site.
    'requires_https' => 'Magic Login には HTTPS が必要です。平文の HTTP では、ログイントークンと、それによって得られる管理者セッションが平文でネットワークを通るため、経路上の誰もがこのサイトの管理者になれてしまいます。まず「ドメインと SSL」タブで証明書を発行してください。',
    'multisite_unsupported' => 'これは WordPress のマルチサイトネットワークです。Magic Login は現時点ではシングルサイトのみに対応しています。ネットワークでは、ここに表示される管理者はネットワーク管理画面にアクセスできないため、見かけより少ない権限でログインすることになります。',
    'not_an_administrator' => 'そのアカウントはこのサイトの管理者ではありません。一覧を開いてから変更された可能性があります。Magic Login を閉じて、もう一度開いて更新してください。',
    'list_failed' => 'このサイトの管理者を一覧できませんでした。このパスに WordPress がインストールされていないか、wp-cli を実行できなかった可能性があります。',
    'list_unreadable' => 'WordPress が管理者一覧ではなく読み取れない内容を返しました。サイトが PHP の注意を出力している可能性が高いため、エラーログを確認してください。',
    'mint_failed' => '使い捨てのログイントークンをこのサイトのデータベースに保存できませんでした。',
    'loader_failed' => 'Magic Login の補助ファイルをこのサイトに書き込めませんでした。',
];
