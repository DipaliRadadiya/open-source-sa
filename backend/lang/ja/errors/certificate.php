<?php

return [

    'no_certifiable_domains' => 'このアプリケーションに証明書を発行できるドメインがありません。まず DNS を確認してください。',
    'force_https_without_certificate' => '有効な証明書なしに HTTPS を強制することはできません。サイトが応答しなくなります。',
    'not_pem' => 'PEM ファイルではないようです。-----BEGIN で始まる必要があります。',
    'key_mismatch' => '秘密鍵が証明書と一致しません。',

    // Why the reachability dry run said no, per domain. The dry run does
    // exactly what Let's Encrypt is about to do, so each of these is a
    // distinct fix — 'SSL failed' would leave the user guessing between
    // DNS, a firewall and their own rewrite rules.
    'precheck' => [
        // The passing verdict. Only the dry-run report needs it: the 422
        // that refuses an issue request never lists a name that passed.
        'ok' => ':domain は準備できています。このサーバーが検証リクエストに正しく応答しました。',
        'dns_missing' => ':domain は名前解決できません。このサーバーを指す DNS A レコードを追加してから再試行してください。',
        'dns_not_pointing' => ':domain は :ip を指しており、このサーバーではありません。',
        'dns_unverifiable' => 'このサーバーはNATの内側にあるため、:domain がこのサーバーを指しているかをここから確認できません。DNSが正しい場合は「それでも発行」を使用してください。検証リクエストは外部から届くため成功します。',
        'behind_proxy' => ':domain はこのサーバーではなく Cloudflare を指しているため、検証リクエストが届きません。証明書の発行中はプロキシを一時停止（グレーの雲）してください。',
        'blocked_ip' => ':domain は :ip を指しています。証明書を発行できる公開アドレスではありません。',
        'unreachable' => ':domain のポート 80 で応答がありませんでした。ファイアウォールがポート 80 を許可し、ウェブサーバーが稼働しているか確認してください。',
        'challenge_redirected' => ':domain は検証リクエストに応答せずリダイレクトしています。証明書が発行されるまで HTTP から HTTPS へのリダイレクトを無効にしてください。',
        'challenge_not_served' => ':domain は応答しましたが、検証ファイルではありませんでした。サイトが /.well-known/ を書き換えている可能性が高いため、リライトルールを確認してください。',
        'precheck_failed' => 'このサーバーに検証ファイルを書き込めなかったため、:domain を確認できませんでした。',
    ],

    // Why a dry run would not start. Not a verdict on any domain — the
    // run never happened, and saying so plainly stops the user reading
    // a refusal as a DNS problem.
    'dry_run' => [
        'issue_in_flight' => 'このサイトの証明書を現在発行中です。完了までお待ちください。certbot は一度に 1 つの処理しか実行できないため、今ドライランを開始しても競合が報告されるだけです。',
    ],
];
