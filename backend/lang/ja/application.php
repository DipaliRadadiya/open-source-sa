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
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'フォーラムソフトウェア — MongoDB または PostgreSQL が必要'],
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
        'php_version_install' => 'このサーバーには :type が動作する PHP バージョン（:range）がありません。先に PHP 画面から PHP :version をインストールしてください。',
        'php_version_none' => 'このサーバーには :type が動作する PHP バージョン（:range）がなく、サーバーのパッケージリポジトリからもインストールできません。',
        'node' => 'このサーバーには Node.js がインストールされていません。',
        'web_server' => 'このアプリケーションは :web_server サーバーではまだ利用できません。',
    ],

    'git_source' => [
        'account' => '連携済みアカウントから',
        'public_url' => '公開リポジトリのURLを貼り付け',
    ],

    'fields' => [
        'database_engine' => 'データベースエンジン',
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
        'mailer_host' => 'smtp.example.com',
        'mailer_port' => '587',
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
        'table_prefix_optional' => '任意。空欄にすると、テーブルは接頭辞なしで作成されます。',
        'start_command' => 'エントリファイル（例:「node server.js」）。「npm start」は不可 — パッケージマネージャーが実際のプロセスをフォークするため、終了シグナルが届きません。',
        'app_port' => '空欄にすると、パネルが空きポートを選びます。',
        'rendering_type' => 'サーバーサイドレンダリングはアプリを実行してプロキシします。他の 2 つは Web サーバーが直接配信するファイルにビルドします — 高速で、常駐させるものがありません。',
        'repository_url' => '公開リポジトリ — アカウント不要。https:// のアドレスを指定してください。',
        'build_command' => 'コード取得後に実行されます。例: composer install --no-dev',
        'deploy_script' => 'コードの取得後に、サイトのユーザーとして、このサイトのPHPバージョンで実行されます。空欄にするとビルドコマンドが使われます。',
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
        'ensure_account' => 'システムアカウントを作成しています',
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
        'script' => 'デプロイスクリプトを実行しています',
        'dependencies' => '依存関係を確認しています',
        'verify' => 'サイトの応答を確認しています',
        'verify_serving' => 'サイトの応答を確認しています',
        'worker' => 'バックグラウンド処理が停止しました',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'このサイトはgitリポジトリからデプロイされているため、タイプを変更できません。デプロイ・ワーカー・環境ファイルの各画面はこのタイプがあるために存在しており、それらを取り除いてもバックグラウンドのワーカーは停止せず、デプロイWebhookもプッシュを受け付け続けます。管理する画面だけが失われることになります。',
        'git_not_a_target' => 'サイトをgitデプロイに変換することはできません。それにはパネルが管理するリポジトリ・ブランチ・デプロイスクリプトが必要で、サーバー上に既にあるファイルからは作成できません。代わりにgitアプリケーションを作成してください。',
        'unchanged' => 'このサイトは既にそのタイプに設定されています。',
        'not_suggestable' => 'このサイトをそのタイプに変更することはできません。ディスク上でパネルが認識できるアプリケーションのみ再ラベル付けできます。それ以外は、サイトが使えない機能を主張することになります。',
        'only_from_generic' => '別のアプリケーションタイプに再ラベル付けできるのは、カスタムPHPまたは静的サイトのみです。このサイトは既に特定のアプリケーションに設定されており、あるアプリケーションを別のものに変えることはラベルではできません。',
        'no_evidence' => 'このサイトには :type らしきものが見つかりません。先にアプリケーションをアップロードしてから、もう一度「検出」を実行してください。パネルは、サイト自身のディレクトリ内にアプリケーションを確認できたときにのみタイプを変更します。',
    ],

    'failure_reason' => [
        'attached_database_engine_mismatch' => 'このアプリケーションにはすでにデータベースが関連付けられていますが、このアプリケーションが使用できないエンジン上で動作しています。関連付けを解除するか、対応するエンジン上のデータベースを関連付けてから再試行してください。',
        'serving_error' => 'アプリケーションは起動しましたが、すべてのリクエストにエラーを返します。アセットが完全にビルドされていない可能性が高いため、アプリケーションログを確認してください。',
        'not_answering' => 'アプリケーションは起動しましたが、リクエストに一度も応答しませんでした。待ち受けていない理由をアプリケーションログで確認してください。',
        'out_of_memory' => 'このステップ中にサーバーのメモリが不足し、システムによって停止されました。メモリを解放するか、スワップを追加してから再試行してください。',
        'no_build_tools' => 'このステップではネイティブモジュールのコンパイルが必要でしたが、このサーバーにはコンパイラがインストールされていません。セットアップ画面からビルドツールをインストールして、もう一度お試しください。別の Node バージョンを選ぶことも有効な場合がありますが、ビルド済みバイナリを用意しているかは各パッケージ次第のため、それだけでは確実な解決策ではありません。',
        'composer_platform' => 'このサイトに設定されたPHPバージョンでは、Composerがこのアプリケーションの依存関係をインストールできませんでした。サイトのPHPバージョン、または必要な拡張機能が、プロジェクトの要件を満たしていません。プロジェクトが対応しているPHPバージョンに変更するか、不足している拡張機能をインストールしてから、再度デプロイしてください。',
        'composer_dependencies_missing' => 'このプロジェクトにはComposerの依存関係が必要ですが、何もインストールされていません。そのためアプリケーションにvendor/autoload.phpが存在せず、すべてのリクエストが失敗します。デプロイスクリプトにcomposer installを実行する手順を追加してから、再度デプロイしてください。',
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

    'supervisor_installing' => 'ワーカーの実行基盤である supervisor をインストールしています。少し時間がかかります。完了したらワーカーを作成し直してください。',

    'placeholder_page' => [
        'lede' => 'このサイトは稼働中です。このページをご自身のものに置き換えてください。それまでは、すべての訪問者にこの画面が表示されます。',
        'php_running' => 'このサイトで PHP が動作しています',
        'step_files_title' => 'ファイルをアップロード',
        'step_files_body' => 'パネルのファイルマネージャーを使うか、このサイトのシステムユーザーで SFTP 接続してください。',
        'step_deploy_title' => 'または git からデプロイ',
        'step_deploy_body' => 'サイトをリポジトリに接続すると、プッシュのたびにパネルが取得してビルドします。',
        'foot' => 'コントロールパネルが作成したプレースホルダーページです。',
    ],

    'disabled_page' => [
        'title' => 'サイトを利用できません',
        'heading' => 'このサイトは一時的に利用できません',
        'lede' => '所有者によってオフラインにされています。しばらくしてからもう一度お試しください。',
        'foot' => 'コントロールパネルによる配信です。',
    ],

    // A deploy that failed after its checkout left the new code live.
    // See Application::codeOnDisk().
    'code_on_disk' => [
        'incomplete' => '最後のデプロイは新しいコードを配置した後に失敗しました。そのため、サイトは完全にはデプロイされていないコミット :commit で動作しています。問題を修正して再度デプロイしてください。',
    ],

    // Why deploy-on-push still needs the webhook added by hand. See
    // WebhookRegistrar.
    'webhook_registration' => [
        'no_account' => 'このサイトは接続済みの Git アカウントではなく公開 URL からデプロイされるため、パネルが Webhook を追加できません。下の URL とシークレットを使ってリポジトリ設定で追加してください。',
        'signing_token' => 'GitLab の署名トークンは GitLab 自身が作成するため、パネルはこの Webhook を追加できません。下の URL とご自身の署名トークンを使って、リポジトリの Webhooks 設定で追加してください。',
        'not_public' => 'パネルのアドレスがインターネットから到達できないため、GitHub、GitLab、Bitbucket は配信できません。パネルに公開アドレスを設定するか、その後で Webhook を手動で追加してください。',
        'provider_refused' => 'Git プロバイダーがパネルによる Webhook の追加を許可しませんでした。接続中のトークンにこのリポジトリの Webhook を管理する権限がない可能性があります。下の URL とシークレットで手動で追加するか、その権限でアカウントを再接続してください。',
    ],
];
