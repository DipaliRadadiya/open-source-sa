<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'プライマリ',
        'alias' => 'エイリアス',
        'redirect' => 'リダイレクト',
    ],

    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'ブログ・ウェブサイト作成'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'ブラウザーからデータベースを管理'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => '稼働監視とステータスページ'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'ワークフロー自動化 (フェアコードライセンス)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'デバイス・API・サービスをつなぐ'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'フォーラムソフトウェア — MongoDB が必要'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'プライベートなファイル同期・共有'],
        'joomla' => ['title' => 'Joomla', 'tagline' => '柔軟なコンテンツ管理システム'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'オンライン学習・コース管理'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'マーケティング自動化とキャンペーン'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => '開発者向けコンテンツ管理'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => '会計・請求管理'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'フラットファイル CMS — データベース不要'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'オンラインストア・EC'],
        'git' => ['title' => 'Gitリポジトリから', 'tagline' => 'GitHub・GitLab・Bitbucket から自分のコードをデプロイ'],
        'php' => ['title' => '空のPHPサイト', 'tagline' => '空のサイト — ファイルは自分でアップロード'],
        'static' => ['title' => '静的サイト', 'tagline' => 'HTML・CSS・JavaScript のみ'],
    ],

    'status' => [
        'pending' => '未デプロイ',
        'provisioning' => 'セットアップ中…',
        'active' => '稼働中',
        'failed' => 'セットアップ失敗',
    ],

    'unavailable' => [
        'database' => 'このアプリケーションには :engines が必要ですが、このサーバーにはありません。',
        'php' => 'このサーバーには PHP がインストールされていません。',
        'node' => 'このサーバーには Node.js がインストールされていません。',
        'web_server' => 'このアプリケーションは :web_server サーバーではまだ利用できません。',
    ],

    'git_source' => [
        'account' => '連携済みアカウントから',
        'public_url' => '公開リポジトリのURLを貼り付け',
    ],

    'fields' => [
        'company_name' => '会社名',
        'company_email' => '会社のメールアドレス',
        'locale' => 'ロケール',
        'site_name' => 'サイト名',
        'language' => '言語',
        'admin_name' => '管理者名',
        'admin_first_name' => '管理者の名',
        'admin_last_name' => '管理者の姓',
        'short_name' => '短縮名',
        'shop_name' => 'ショップ名',
        'country' => '国',
        'timezone' => 'タイムゾーン',
        'rendering_type' => 'レンダリング方式',
        'name' => '名前',
        'domain' => 'ドメイン',
        'system_user_id' => 'システムユーザー',
        'php_version' => 'PHPバージョン',
        'node_version' => 'Node.jsバージョン',
        'app_port' => 'アプリのポート',
        'web_root' => 'ウェブルート',
        'build_command' => 'ビルドコマンド',
        'deploy_script' => 'デプロイスクリプト',
        'start_command' => '起動コマンド',
        'package_manager' => 'パッケージマネージャー',
        'git_source' => 'ソース',
        'git_account_id' => 'Gitアカウント',
        'repository' => 'リポジトリ',
        'repository_url' => 'リポジトリURL',
        'branch' => 'ブランチ',
        'site_title' => 'サイトタイトル',
        'admin_user' => '管理者ユーザー名',
        'admin_username' => '管理者ユーザー名',
        'admin_email' => '管理者メールアドレス',
        'admin_password' => '管理者パスワード',
        'site_language' => 'サイトの言語',
        'table_prefix' => 'テーブル接頭辞',
        'mailer_name' => '送信者名',
        'mailer_email' => '送信元アドレス',
        'mailer_host' => 'SMTP ホスト',
        'mailer_port' => 'SMTP ポート',
        'mailer_username' => 'SMTP ユーザー名',
        'mailer_password' => 'SMTP パスワード',
    ],

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'site_title' => 'マイサイト',
        'site_name' => 'マイサイト',
        'shop_name' => 'マイショップ',
        'company_name' => 'マイカンパニー',
        'short_name' => 'mysite',
        'mailer_name' => 'マイサイト',
        'admin_email' => 'you@example.com',
        'company_email' => 'you@example.com',
        'mailer_email' => 'no-reply@example.com',
        'mailer_username' => 'no-reply@example.com',
        'timezone' => 'Asia/Tokyo',
        'repository_url' => 'https://github.com/you/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'help' => [
        'table_prefix_random' => '空欄にするとランダムな接頭辞が生成され、データベースを共有した場合でもテーブルが混ざりません。',
        'timezone' => 'サイトのタイムゾーン。例: America/New_York、Asia/Tokyo。設定 → 一般 → タイムゾーンを参照してください。',
        'table_prefix_optional' => '任意。空欄にすると接頭辞なしになります — Akaunting は Laravel ベースで、標準の接頭辞はありません。',
        'start_command' => 'エントリファイル（例:「node server.js」）。「npm start」は不可 — パッケージマネージャーが実際のプロセスをフォークするため、終了シグナルが届きません。',
        'app_port' => '空欄にすると、パネルが空きポートを選びます。',
        'rendering_type' => 'サーバーサイドレンダリングはアプリを実行してプロキシします。他の 2 つは Web サーバーが直接配信するファイルにビルドします — 高速で、常駐させるものがありません。',
        'repository_url' => '公開リポジトリ — アカウント不要。https:// のアドレスを指定してください。',
        'build_command' => 'コード取得後に実行されます。例: composer install --no-dev',
        'deploy_script' => 'コード取得後に、サイトのユーザーとして実行されます。空にするとビルドコマンドが使われます。',
        'package_manager' => '依存関係のインストールとビルドに使うツールです。下のビルドコマンドを自動入力します — 後から自由に編集できます。',
    ],

    'steps' => [
        'create_database' => 'データベースを作成中',
        'download' => 'アプリケーションをダウンロード中',
        'extract' => 'ファイルを展開中',
        'configure' => '設定を書き込み中',
        'install_cli' => 'セットアップツールをインストール中',
        'install_app' => 'インストーラーを実行中',
        'init' => 'リポジトリを設定中',
        'fetch' => '最新のコードを取得中',
        'checkout' => 'ブランチをチェックアウト中',
        'seed_env' => '環境ファイルを準備しています',
        'build' => 'ビルドコマンドを実行中',
        'write_credential' => 'gitアクセスを準備中',
        'check_account' => 'システムアカウントを確認中',
        'create_directory' => 'ディレクトリを作成中',
        'set_ownership' => '所有者を設定中',
        'placeholder' => '仮ページを作成中',
        'write_config' => 'サイト設定を書き込み中',
        'test_config' => '設定を検証中',
        'reload' => 'ウェブサーバーを再読み込み中',
        'start_app' => 'アプリケーションを起動しています',
        'write_unit' => 'サービスを準備しています',
        'restart_app' => 'アプリケーションを再起動しています',
        'harden' => 'セキュリティ設定を適用しています',
        'trust_domain' => 'ドメインを許可しています',
        'set_password' => '管理者パスワードを設定しています',
        'verify_serving' => 'サイトの応答を確認しています',
        'worker' => 'バックグラウンド処理が停止しました',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'serving_error' => 'アプリケーションは起動しましたが、すべてのリクエストにエラーを返します。アセットが完全にビルドされていない可能性が高いため、アプリケーションログを確認してください。',
        'not_answering' => 'アプリケーションは起動しましたが、リクエストに一度も応答しませんでした。待ち受けていない理由をアプリケーションログで確認してください。',
        'out_of_memory' => 'このステップ中にサーバーのメモリが不足し、システムによって停止されました。メモリを解放するか、スワップを追加してから再試行してください。',
    ],

    'port_free' => 'ポート :port は空いています。',

    'rendering' => [
        'php' => 'PHP アプリケーション (Laravel、Symfony、素の PHP)',
        'ssr' => 'サーバーサイドレンダリング（プロセスを実行）',
        'csr' => 'クライアントサイドレンダリング（ファイルにビルド）',
        'static' => '静的サイト（ファイルにビルド）',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];
