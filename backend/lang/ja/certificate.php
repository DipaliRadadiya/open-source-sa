<?php

return [

    // Where a certificate came from. Shown as a badge, so these read as nouns.
    'type' => [
        'letsencrypt' => 'Let\'s Encrypt',
        'custom' => 'アップロード',
        'self_signed' => '自己署名',
    ],

    // Issuance failures, keyed by the code the panel classifies certbot's
    // output into. Each says what to do about it, because 'it failed' is the
    // least useful sentence a panel can produce about a certificate.
    'failed' => [
        'rate_limited' => 'このドメインに対して最近発行された証明書が多すぎます。最も古いものから 1 週間後に上限がリセットされます。その後に再試行するか、証明書をアップロードしてください。',
        'rate_limited_failures' => 'この 1 時間でこのドメインの失敗が多すぎます。Let\'s Encrypt の上限は 5 回です。1 時間お待ちください。',
        'unreachable' => '検証リクエストがこのサーバーに届きませんでした。ポート 80 が開いており、他のものが応答していないか確認してください。',
        'dns_not_pointing' => 'ドメインがこのサーバーを指していません。DNS レコードをこのサーバーに向け、反映を待ってから再試行してください。',
        'challenge_not_served' => '検証ファイルが正しく配信されませんでした。サイトが /.well-known をリダイレクトしているか、Cloudflare などのプロキシがこのサーバーの代わりに応答しています。',
        'certbot_missing' => 'このサーバーに certbot がインストールされていません。',
        'no_certifiable_domains' => 'このサイトに証明書を発行できるドメインがありません。まず DNS を確認してください。',
        'self_sign_failed' => '自己署名証明書を生成できませんでした。',
        'file_missing' => 'このサーバーに証明書ファイルがありません。再発行してください。',
        'unknown' => '証明書を発行できませんでした。',
    ],

];
