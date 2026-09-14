<?php

/*
 * Copy for the Storage destinations integration — S3-compatible remote
 * targets that backups are uploaded to. The keys in this file render the
 * connect form, the row labels and the outcome of the test-connection probe.
 */

return [
    'drivers' => [
        's3' => 'S3 互換',
        'ftp' => 'FTP',
        'sftp' => 'SFTP',
        'google_drive' => 'Google ドライブ',
    ],

    'fields' => [
        'name' => '表示名',
        'endpoint' => 'エンドポイント URL',
        'region' => 'リージョン',
        'bucket' => 'バケット',
        'prefix' => 'キープレフィックス (任意)',
        'access_key' => 'アクセスキー',
        'secret_key' => 'シークレットキー',
        'host' => 'ホスト',
        'port' => 'ポート',
        'username' => 'ユーザー名',
        'password' => 'パスワード',
        'root' => 'リモートディレクトリ',
        'ssl' => 'TLS を使用 (FTPS)',
        'passive' => 'パッシブモード',
        'private_key' => '秘密鍵',
        'passphrase' => '鍵のパスフレーズ',
        'host_fingerprint' => 'ホスト鍵のフィンガープリント',
        'service_account_json' => 'サービスアカウントキー (JSON)',
        'folder_id' => '共有ドライブのフォルダー ID',
        'drive_name' => '共有ドライブ',
        'client_email' => 'サービスアカウントのアドレス',
    ],

    'placeholders' => [
        'endpoint' => 'https://s3.amazonaws.com',
        'region' => 'us-east-1',
        'prefix' => 'backups/production/',
        'host' => 'backup.example.com',
        'root' => 'backups/',
    ],

    'help' => [
        'name' => '連携一覧で保存先を見分けるための短いラベルです。',
        'endpoint' => 'AWS の場合は既定のままにします。MinIO、R2、Backblaze B2、Wasabi などの場合は設定してください。',
        'region' => 'バケットが存在するリージョン (AWS の場合のみ必要)。',
        'prefix' => 'バケット内の任意のパスプレフィックス (先頭のスラッシュなし)。',
        'access_key' => '書き込み専用 — API が返すことはありません。',
        'host' => 'バックアップを保存するサーバーのホスト名または IP アドレス。',
        'port' => '空欄の場合は既定値を使用します。',
        'root' => '書き込み先のサーバー上のディレクトリ。空欄の場合はログイン先をそのまま使用します。',
        'ssl' => '強く推奨します。無効にすると、パスワードとバックアップ全体が平文で送信されます。',
        'passive' => 'サーバーが別途要求しない限り、有効のままにしてください。',
        'private_key' => '秘密鍵全体を貼り付けてください。パスワードの代わりに使用します。',
        'passphrase' => '秘密鍵自体が暗号化されている場合のみ必要です。',
        'host_fingerprint' => '初回接続時に記録し、以降は照合します。確実を期すにはサーバー上の鍵と比較してください。',
        'plain_ftp_warning' => 'TLS が無効です。パスワードとすべてのバックアップが暗号化されずに送信されます。',
        'service_account_json' => 'サービスアカウントの JSON キーファイル全体を貼り付けてください。',
        'folder_id' => 'フォルダー URL の /folders/ 以降の部分です。リンク全体ではありません。',
        'drive_shared_only' => 'Google Workspace の共有ドライブのみ利用できます。サービスアカウントには自身の保存容量がないため、個人のドライブへのアップロードは空のアカウントでも拒否されます。',
        'drive_share_with' => 'テストの前に、共有ドライブのフォルダーをサービスアカウントのアドレスと共有してください。',
    ],

    'status' => [
        'connected' => '接続済み',
        'never_tested' => '未テスト',
        'failed' => '前回のテストに失敗',
    ],

    'test' => [
        'success' => '接続に成功しました。',
        'failure' => '保存先に接続できませんでした。',
        'invalid_credentials' => '保存先が認証情報を拒否しました。',
        'unreachable' => '保存先のエンドポイントに到達できませんでした。',
        'mismatch' => '保存先が書き込んだバイトと異なるバイトを返しました。',
        'forbidden_host' => 'このエンドポイントアドレスは許可されていません。',
        'invalid_endpoint' => 'バケットの有効な https:// エンドポイント URL を入力してください。',
        'invalid_host' => '有効なホスト名または IP アドレスを入力してください。',
        'host_key_mismatch' => 'サーバーが記録済みとは異なるホスト鍵を提示しました。接続を中止しました。',
        'invalid_private_key' => '秘密鍵を読み取れませんでした。全体が貼り付けられているか確認してください。',
        'root_missing' => '保存先のフォルダーがサーバー上に存在しません。フォルダーを作成するか、パスを修正してください。',
        'drive_personal' => 'そのフォルダーは個人のドライブにあります。サービスアカウントには保存容量がないためバックアップは拒否されます — 共有ドライブのフォルダーを使用してください。',
        'drive_not_shared' => 'フォルダーは存在しますが、このサービスアカウントにはアクセス権がありません。',
        'drive_folder_missing' => 'その ID のフォルダーは見つかりませんでした。',
        'drive_not_a_folder' => 'その ID はフォルダーではなくファイルを指しています。',
        'drive_bad_key' => 'サービスアカウントキーを読み取れませんでした。JSON ファイル全体を貼り付けてください。',
        'drive_quota' => '保存容量の不足により Google がアップロードを拒否しました。個人のドライブで起きる現象です。',
        'drive_incomplete' => 'テストの前にサービスアカウントキーとフォルダー ID を入力してください。',
    ],

    'delete' => [
        'in_use' => ':name を削除できません — :applications がまだこの保存先を使用しています。先にそれらのバックアップ対象を削除するか、参照先を変更してください。',
        'and_more' => '他 :count 件',
    ],

    'validation' => [
        'sftp_auth_required' => 'パスワードか秘密鍵のいずれかを指定してください。',
    ],
];
