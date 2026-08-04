<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'S3 互換',
    ],

    'fields' => [
        'name' => '表示名',
        'endpoint' => 'エンドポイント URL',
        'region' => 'リージョン',
        'bucket' => 'バケット',
        'prefix' => 'キープレフィックス (任意)',
        'access_key' => 'アクセスキー',
        'secret_key' => 'シークレットキー',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
    ],

    'help' => [
        'name' => '連携一覧で保存先を見分けるための短いラベルです。',
        'endpoint' => 'AWS の場合は既定のままにします。MinIO、R2、Backblaze B2、Wasabi などの場合は設定してください。',
        'region' => 'バケットが存在するリージョン (AWS の場合のみ必要)。',
        'prefix' => 'バケット内の任意のパスプレフィックス (先頭のスラッシュなし)。',
        'access_key' => '書き込み専用 — API が返すことはありません。',
    ],

    'status' => [
        'connected' => '接続済み',
        'never_tested' => '未テスト',
    ],

    'test' => [
        'success' => '接続に成功しました。',
        'failure' => '保存先に接続できませんでした。',
        'invalid_credentials' => '保存先が認証情報を拒否しました。',
        'unreachable' => '保存先のエンドポイントに到達できませんでした。',
        'mismatch' => '保存先が書き込んだバイトと異なるバイトを返しました。',
        'forbidden_host' => 'このエンドポイントアドレスは許可されていません。',
        'invalid_endpoint' => 'バケットの有効な https:// エンドポイント URL を入力してください。',
    ],

    'delete' => [
        'in_use' => ':name を削除できません — 1 つ以上のバックアップ対象がこの保存先を参照しています。先にそれらを削除するか、参照先を変更してください。',
    ],
];
